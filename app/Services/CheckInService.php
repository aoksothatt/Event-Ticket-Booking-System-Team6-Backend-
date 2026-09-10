<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Exceptions\CheckInException;
use App\Models\Ticket;
use App\Models\TicketCheckin;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CheckInService
{
    public function __construct(
        private readonly TicketExpirationService $expiration,
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * Resolve a ticket by its secure QR token or human-readable ticket code.
     */
    public function resolve(string $token): ?Ticket
    {
        return Ticket::with([
            'ticketType.event.venue',
            'booking.user',
            'user',
            'event',
        ])
            ->where(fn ($q) => $q
                ->where('qr_token', $token)
                ->orWhere('ticket_code', $token))
            ->latest()
            ->first();
    }

    /**
     * Describe the ticket's usability without mutating anything.
     * Used by the scanner for the "preview" step.
     *
     * @return array{valid: bool, status: string, message: string}
     */
    public function validate(Ticket $ticket): array
    {
        if ($ticket->status === TicketStatus::USED->value) {
            return [
                'valid' => false,
                'status' => 'used',
                'message' => 'This ticket has already been checked in.',
            ];
        }

        if ($ticket->status === TicketStatus::CANCELLED->value) {
            return [
                'valid' => false,
                'status' => 'cancelled',
                'message' => 'This ticket has been cancelled and cannot be used.',
            ];
        }

        if ($ticket->status === TicketStatus::REFUNDED->value) {
            return [
                'valid' => false,
                'status' => 'refunded',
                'message' => 'This ticket has been refunded and cannot be used.',
            ];
        }

        if ($ticket->status === TicketStatus::EXPIRED->value) {
            return [
                'valid' => false,
                'status' => 'expired',
                'message' => 'This ticket has expired.',
            ];
        }

        if (! in_array($ticket->status, [TicketStatus::ACTIVE->value, TicketStatus::DONE->value], true)) {
            return [
                'valid' => false,
                'status' => strtolower($ticket->status),
                'message' => 'This ticket is not valid for check-in.',
            ];
        }

        if ($this->expiration->isExpired($ticket)) {
            return [
                'valid' => false,
                'status' => TicketStatus::EXPIRED->value,
                'message' => 'This ticket has expired because the event has ended.',
            ];
        }

        $statusLabel = $ticket->status === TicketStatus::DONE->value ? 'ready' : 'active';

        return [
            'valid' => true,
            'status' => $statusLabel,
            'message' => $ticket->status === TicketStatus::DONE->value
                ? 'Valid ticket (pending activation) — ready for check-in.'
                : 'Valid ticket — ready for check-in.',
        ];
    }

    /**
     * Atomically check a customer in.
     *
     * Every validation rule is enforced inside a database transaction while
     * the ticket row is locked (SELECT ... FOR UPDATE). Two staff scanning the
     * same QR code simultaneously are serialized — only the first succeeds;
     * the second sees status USED and receives "Ticket already used."
     *
     * Status flow: DONE → ACTIVE → USED (auto-activates DONE tickets).
     *
     * @throws CheckInException when any validation rule fails
     */
    public function checkIn(Ticket $ticket, User $actor, array $context = []): TicketCheckin
    {
        $ticketCheckin = DB::transaction(function () use ($ticket, $actor, $context) {
            // Lock the ticket row so concurrent scans serialize.
            $locked = Ticket::query()
                ->lockForUpdate()
                ->with(['event', 'ticketType'])
                ->find($ticket->id);

            if ($locked === null) {
                $this->activityLog->log(
                    'checkin.not_found',
                    'Scan referenced a ticket that does not exist',
                    null,
                    metadata: ['ticket_id' => $ticket->id],
                );

                throw new CheckInException('Ticket not found.', 'not_found');
            }

            if ($locked->status === TicketStatus::USED->value) {
                $this->activityLog->log(
                    'checkin.duplicate_attempt',
                    'Duplicate check-in attempt blocked',
                    $actor->id,
                    metadata: ['ticket_id' => $locked->id, 'ticket_code' => $locked->ticket_code],
                );

                throw new CheckInException('Ticket already used.', 'used');
            }

            if ($locked->status === TicketStatus::CANCELLED->value) {
                throw new CheckInException('This ticket has been cancelled.', 'cancelled');
            }

            if ($locked->status === TicketStatus::REFUNDED->value) {
                throw new CheckInException('This ticket has been refunded.', 'refunded');
            }

            if (! in_array($locked->status, [TicketStatus::ACTIVE->value, TicketStatus::DONE->value], true)) {
                throw new CheckInException('This ticket is not active and cannot be checked in.', 'inactive');
            }

            if ($this->expiration->isExpired($locked)) {
                $this->activityLog->log(
                    'checkin.expired',
                    'Expired ticket rejected at gate',
                    $actor->id,
                    metadata: ['ticket_id' => $locked->id, 'ticket_code' => $locked->ticket_code],
                );

                throw new CheckInException('This ticket has expired because the event has ended.', 'expired');
            }

            // Auto-activate DONE tickets (DONE → ACTIVE) before marking as USED.
            if ($locked->status === TicketStatus::DONE->value) {
                $locked->forceFill(['status' => TicketStatus::ACTIVE->value])->save();

                $locked->logs()->create([
                    'action' => TicketStatus::ACTIVE->value,
                    'description' => 'Ticket auto-activated during check-in scan',
                    'actor_id' => $actor->id,
                    'metadata' => [
                        'ticket_code' => $locked->ticket_code,
                        'previous_status' => TicketStatus::DONE->value,
                    ],
                ]);

                $this->activityLog->log(
                    'checkin.auto_activated',
                    'DONE ticket auto-activated during check-in',
                    $actor->id,
                    metadata: ['ticket_id' => $locked->id, 'ticket_code' => $locked->ticket_code],
                );
            }

            $actorOrganizerId = $this->organizerIdFor($actor, $locked);

            // Mark the ticket as used (ACTIVE → USED).
            $locked->forceFill([
                'status' => TicketStatus::USED->value,
                'used_at' => now(),
            ])->save();

            // Audit trail entry on the ticket itself.
            $locked->logs()->create([
                'action' => TicketStatus::USED->value,
                'description' => 'Customer checked in via QR scan',
                'actor_id' => $actor->id,
                'metadata' => [
                    'ticket_code' => $locked->ticket_code,
                    'checked_by' => $actor->id,
                    'organizer_id' => $actorOrganizerId,
                ],
            ]);

            // Permanent check-in history row.
            return TicketCheckin::create([
                'ticket_id' => $locked->id,
                'event_id' => $locked->event_id,
                'staff_id' => $actor->id,
                'organizer_id' => $actorOrganizerId,
                'checked_in_at' => now(),
                'device_name' => $context['device_name'] ?? null,
                'ip_address' => $context['ip_address'] ?? null,
                'source' => $context['source'] ?? 'staff',
            ]);
        });

        $this->activityLog->log(
            'checkin.success',
            'Check-in completed successfully',
            $actor->id,
            metadata: ['ticket_id' => $ticketCheckin->ticket_id, 'ticket_code' => $ticket->ticket_code],
        );

        return $ticketCheckin->fresh(['ticket.user', 'ticket.event', 'ticket.ticketType', 'staff', 'organizer']);
    }

    /**
     * Resolve which organizer a check-in should be attributed to.
     * Admin scans fall back to the event's owning organizer.
     */
    protected function organizerIdFor(User $actor, Ticket $ticket): ?int
    {
        if ($actor->role === 'admin') {
            return $ticket->event?->organizer_id;
        }

        return $actor->activeOrganizer()?->id ?? $ticket->event?->organizer_id;
    }
}
