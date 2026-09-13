<?php

namespace App\Http\Controllers;

use App\Models\CheckIn;
use App\Models\Ticket;
use App\Services\TicketExpirationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketController extends Controller
{
    /**
     * List actual customer tickets (admin/organizer view).
     */
    public function index(Request $request)
    {
        $tickets = Ticket::with([
            'user',
            'booking',
            'ticketType.event',
            'bookingItem',
            'logs.actor',
        ])
            ->when($request->search, function ($q, $search) {
                $q->where('ticket_code', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('ticketType.event', fn ($e) => $e->where('title', 'like', "%{$search}%"));
            })
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $tickets,
        ]);
    }

    /**
     * Show a single ticket with its full audit trail.
     */
    public function show($id)
    {
        $ticket = Ticket::with([
            'user',
            'booking',
            'ticketType.event',
            'bookingItem',
            'logs.actor',
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $ticket,
        ]);
    }

    /**
     * The currently authenticated customer's actual tickets.
     */
    public function myTickets(Request $request)
    {
        $tickets = Ticket::with([
            'ticketType.event.venue',
            // A customer reaches this endpoint immediately after Bakong
            // confirms payment. Include the booking and its payment record
            // so My Tickets can show that the order is settled.
            'booking.payments',
            'logs',
        ])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $tickets,
        ]);
    }

    /**
     * The authenticated customer's historical (finished/cancelled) tickets,
     * including the full audit trail for each one.
     */
    public function history(Request $request)
    {
        $tickets = Ticket::history()
            ->with([
                'ticketType.event.venue',
                'booking',
                'logs.actor',
                'event',
            ])
            ->where('user_id', $request->user()->id)
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $tickets,
        ]);
    }

    /**
     * Verify/check in a ticket by its secure QR token.
     * Marks the ticket as used and records a check-in.
     */
    public function verify(Request $request)
    {
        $validated = $request->validate([
            'qr_token' => 'required|string',
        ]);

        $ticket = Ticket::with(['ticketType.event', 'user', 'booking'])
            ->where(function ($q) use ($validated) {
                $q->where('qr_token', $validated['qr_token'])
                    ->orWhere('ticket_code', $validated['qr_token']);
            })
            ->first();

        if (! $ticket) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid ticket. Please check the QR code and try again.',
            ], 422);
        }

        // Guard states (kept in English for clear scanner feedback).
        // An already-used ticket is NOT an error for staff — the person is
        // simply already inside. Return the existing check-in so the scanner
        // shows it as a successful entry instead of a red failure.
        if ($ticket->status === Ticket::USED) {
            $existing = $ticket->checkIns()
                ->latest('checked_in_at')
                ->with('checkedBy')
                ->first();

            return response()->json([
                'success' => true,
                'already_checked_in' => true,
                'message' => 'This ticket has already been checked in.',
                'data' => $ticket,
                'check_in' => $existing,
            ], 200);
        }

        if ($ticket->status !== Ticket::ACTIVE) {
            return response()->json([
                'success' => false,
                'message' => 'This ticket is not active and cannot be checked in.',
            ], 422);
        }

        // Belt-and-braces on-demand expiry check (in case the scheduler hasn't
        // run yet this interval).
        $expiration = app(TicketExpirationService::class);
        if ($expiration->isExpired($ticket)) {
            return response()->json([
                'success' => false,
                'message' => 'This ticket has expired because the event has ended.',
            ], 422);
        }

        try {
            $result = $this->performCheckIn($ticket, (int) $request->user()->id);

            return response()->json([
                'success' => true,
                'message' => 'Ticket verified and checked in successfully.',
                'data' => $ticket->fresh(['ticketType.event', 'user', 'booking']),
                'check_in' => $result,
            ], 200);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to verify ticket. Please try again.',
            ], 500);
        }
    }

    /**
     * STEP 1 of the two-step check-in flow.
     *
     * Look up a ticket by its secure QR token / ticket code WITHOUT mutating
     * anything, so the frontend can display the ticket details (ticket number,
     * status, event, ticket type, customer, booking, expiry) and let the
     * operator decide to check it in. Returns a `valid` flag + a friendly
     * `message`, and the existing check-in record when the ticket was already
     * scanned.
     */
    public function lookup(Request $request)
    {
        $validated = $request->validate([
            'ticket_code' => 'required|string',
        ]);

        $ticket = $this->resolveTicket($validated['ticket_code']);

        if (! $ticket) {
            return response()->json([
                'success' => false,
                'status' => 'not_found',
                'message' => 'Invalid ticket. Please check the QR code and try again.',
            ], 422);
        }

        $state = $this->ticketState($ticket);
        $latest = $ticket->checkIns()->latest('checked_in_at')->with('checkedBy')->first();

        return response()->json([
            'success' => true,
            'valid' => $state['valid'],
            'status' => $state['status'],
            'message' => $state['message'],
            'data' => $ticket,
            'check_in' => $latest,
        ], 200);
    }

    /**
     * STEP 2 of the two-step check-in flow.
     *
     * Performs the actual check-in transactionally: flips the ticket to USED
     * (with a pessimistic row lock so two concurrent scans cannot double
     * check-in) and creates the CheckIn record + audit log. Atomic in one DB
     * transaction — the ticket locks permanently on first success.
     */
    public function checkIn(Request $request)
    {
        $validated = $request->validate([
            'ticket_code' => 'required|string',
        ]);

        $ticket = $this->resolveTicket($validated['ticket_code']);

        if (! $ticket) {
            return response()->json([
                'success' => false,
                'status' => 'not_found',
                'message' => 'Invalid ticket QR code.',
            ], 422);
        }

        // Already checked in → report the existing entry, do not create a dup.
        if ($ticket->status === Ticket::USED) {
            $existing = $this->latestCheckIn($ticket);

            return response()->json([
                'success' => true,
                'already_checked_in' => true,
                'status' => 'used',
                'message' => 'This ticket has already been checked in.',
                'data' => $ticket,
                'check_in' => $existing,
            ], 200);
        }

        if (! in_array($ticket->status, [Ticket::ACTIVE, Ticket::DONE], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This ticket is not active and cannot be checked in.',
            ], 422);
        }

        $expiration = app(TicketExpirationService::class);
        if ($expiration->isExpired($ticket)) {
            return response()->json([
                'success' => false,
                'message' => 'This ticket has expired because the event has ended.',
            ], 422);
        }

        try {
            $checkIn = $this->performCheckIn($ticket, (int) $request->user()->id);

            return response()->json([
                'success' => true,
                'status' => 'checked_in',
                'message' => 'Check-in successful. Ticket is now used.',
                'data' => $ticket->fresh(['ticketType.event.venue', 'booking.user', 'user']),
                'check_in' => $checkIn->load('checkedBy'),
            ], 200);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to complete check-in. Please try again.',
            ], 500);
        }
    }

    /**
     * Locate a ticket by either its secure QR token or its ticket code.
     * Eager loads everything the check-in screen needs to render.
     */
    private function resolveTicket(string $token): ?Ticket
    {
        return Ticket::with([
            'ticketType.event.venue',
            'booking.user',
            'user',
            'checkIns.checkedBy',
        ])
            ->where(fn ($q) => $q->where('qr_token', $token)->orWhere('ticket_code', $token))
            ->latest()
            ->first();
    }

    private function latestCheckIn(Ticket $ticket): ?CheckIn
    {
        return $ticket->checkIns()
            ->latest('checked_in_at')
            ->with('checkedBy')
            ->first();
    }

    /**
     * Describe the ticket's usability for the check-in screen without
     * mutating it. The status key is one of: active | done | used | expired |
     * cancelled | refunded.
     */
    private function ticketState(Ticket $ticket): array
    {
        if ($ticket->status === Ticket::USED) {
            return [
                'valid' => false,
                'status' => 'used',
                'message' => 'This ticket has already been checked in.',
            ];
        }

        if ($ticket->status === Ticket::CANCELLED) {
            return [
                'valid' => false,
                'status' => 'cancelled',
                'message' => 'This ticket has been cancelled.',
            ];
        }

        if ($ticket->status === Ticket::REFUNDED) {
            return [
                'valid' => false,
                'status' => 'refunded',
                'message' => 'This ticket has been refunded.',
            ];
        }

        if (! in_array($ticket->status, [Ticket::ACTIVE, Ticket::DONE], true)) {
            return [
                'valid' => false,
                'status' => strtolower($ticket->status),
                'message' => 'This ticket is not valid for check-in.',
            ];
        }

        if (app(TicketExpirationService::class)->isExpired($ticket)) {
            return [
                'valid' => false,
                'status' => 'expired',
                'message' => 'This ticket has expired because the event has ended.',
            ];
        }

        $statusLabel = $ticket->status === Ticket::DONE ? 'ready' : 'active';

        return [
            'valid' => true,
            'status' => $statusLabel,
            'message' => $ticket->status === Ticket::DONE
                ? 'Valid ticket (pending activation) — ready for check-in.'
                : 'Valid ticket — ready for check-in.',
        ];
    }

    /**
     * Atomic check-in: lock the ticket row, verify it is still ACTIVE or DONE,
     * auto-activate if DONE, mark it used, and record the CheckIn — all in one
     * DB transaction.
     *
     * The row lock (SELECT ... FOR UPDATE) serializes concurrent scans, so a
     * ticket can only ever be checked in once even if two operators scan it
     * at the same moment.
     *
     * @throws \RuntimeException when the ticket is no longer checkable
     */
    private function performCheckIn(Ticket $ticket, int $actorId): CheckIn
    {
        return DB::transaction(function () use ($ticket, $actorId) {
            $locked = Ticket::with([
                'ticketType.event.venue',
                'booking.user',
                'user',
            ])->lockForUpdate()->find($ticket->id);

            if (! $locked || ! in_array($locked->status, [Ticket::ACTIVE, Ticket::DONE], true)) {
                throw new \RuntimeException('This ticket is not active and cannot be checked in.');
            }

            // Auto-activate DONE tickets before marking as USED.
            if ($locked->status === Ticket::DONE) {
                $locked->forceFill(['status' => Ticket::ACTIVE])->save();

                $locked->logs()->create([
                    'action' => Ticket::ACTIVE,
                    'description' => 'Ticket auto-activated during check-in scan',
                    'actor_id' => $actorId,
                    'metadata' => [
                        'ticket_code' => $locked->ticket_code,
                        'previous_status' => Ticket::DONE,
                    ],
                ]);
            }

            $locked->markAsUsed($actorId);

            return CheckIn::create([
                'booking_id' => $locked->booking_id,
                'ticket_id' => $locked->id,
                'checked_by' => $actorId,
                'checked_in_at' => now(),
                'status' => 'checked_in',
            ]);
        });
    }

    /**
     * Admin cancels an actual ticket if business rules allow.
     */
    public function cancel(Request $request, $id)
    {
        $ticket = Ticket::findOrFail($id);

        if ($ticket->status === Ticket::USED) {
            return response()->json([
                'success' => false,
                'message' => 'A used ticket cannot be cancelled.',
            ], 422);
        }

        try {
            $ticket->cancel($request->user()->id);

            return response()->json([
                'success' => true,
                'message' => 'Ticket cancelled successfully.',
                'data' => $ticket->fresh(['logs']),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel ticket. Please try again.',
            ], 500);
        }
    }

    /**
     * Self check-in: the ticket OWNER scans their own QR and is checked in
     * automatically. Security rules (unlike the staff verify endpoint):
     *
     *  - The ticket MUST belong to the authenticated user (no scanning others').
     *  - Only ACTIVE tickets can be used (already-used/expired/cancelled are blocked).
     *  - Reuses the on-demand expiry check (event must not have ended).
     *  - Self check-in only works INSIDE the event time window, so a user
     *    cannot "check in" from home before the event even starts.
     *
     * Staff verification (POST /tickets/verify) is intentionally left separate
     * and strict.
     */
    public function selfCheckIn(Request $request)
    {
        $validated = $request->validate([
            'ticket_code' => 'required|string',
        ]);

        $ticket = Ticket::with(['ticketType.event', 'booking', 'user'])
            ->where('user_id', $request->user()->id)
            ->where(function ($q) use ($validated) {
                $q->where('qr_token', $validated['ticket_code'])
                    ->orWhere('ticket_code', $validated['ticket_code']);
            })
            ->first();

        if (! $ticket) {
            return response()->json([
                'success' => false,
                'message' => 'No matching ticket was found for your account. Please check the QR code and try again.',
            ], 422);
        }

        if ($ticket->status === Ticket::USED) {
            $existing = $ticket->checkIns()
                ->latest('checked_in_at')
                ->with('checkedBy')
                ->first();

            return response()->json([
                'success' => true,
                'already_checked_in' => true,
                'message' => 'You have already checked in. Enjoy the event!',
                'data' => $ticket,
                'check_in' => $existing,
            ], 200);
        }

        if (! in_array($ticket->status, [Ticket::ACTIVE, Ticket::DONE], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This ticket is not active and cannot be checked in.',
            ], 422);
        }

        $expiration = app(TicketExpirationService::class);

        if ($expiration->isExpired($ticket)) {
            return response()->json([
                'success' => false,
                'message' => 'This ticket has expired because the event has already ended.',
            ], 422);
        }

        // Only allow self check-in while the event is actually running.
        if (! $expiration->isInsideEventWindow($ticket)) {
            return response()->json([
                'success' => false,
                'message' => 'Check-in is only available during the event hours: '.$expiration->eventWindowLabel($ticket).'.',
            ], 422);
        }

        try {
            $checkIn = $this->performCheckIn($ticket, (int) $request->user()->id);

            return response()->json([
                'success' => true,
                'message' => 'Check-in successful. Enjoy the event!',
                'data' => $ticket->fresh(['ticketType.event', 'ticketType.event.venue', 'booking', 'user']),
                'check_in' => $checkIn->load('checkedBy'),
            ], 200);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to complete check-in. Please try again.',
            ], 500);
        }
    }
}
