<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TicketExpirationService
{
    /**
     * Expire every ACTIVE and DONE ticket whose owning event has already ended.
     *
     * Because events only store a TIME column for end_time, we must combine
     * it with end_date to build a real timestamp. Tickets whose event end
     * datetime is in the past are marked EXPIRED and logged.
     *
     * When a user id is given, only that user's tickets are expired — used
     * on-demand by the customer "My Tickets" endpoints so an ended event's
     * ticket leaves the current list immediately instead of waiting for the
     * scheduled command to run.
     *
     * @param int|null $userId Scope the expiry to a single user.
     * @return int number of tickets expired
     */
    public function expireTickets(?int $userId = null): int
    {
        // event.auto_handle_past (default on) governs whether tickets are
        // auto-expired once their event has ended. Turning it off keeps
        // tickets in their current state and stops both the scheduled
        // `tickets:expire` command and the on-demand customer-ticket calls.
        if (! Setting::value('event.auto_handle_past', true)) {
            return 0;
        }

        $now = now('UTC');

        // Compute the real event-end timestamp (end_date + end_time) so the
        // comparison is done against an actual timestamp, not raw TIME data.
        $activeTickets = Ticket::query()
            ->whereIn('status', [Ticket::ACTIVE, Ticket::DONE])
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->with('event')
            ->get();

        $toExpire = $activeTickets->filter(function (Ticket $ticket) use ($now) {

            // If the ticket was manually given an explicit expiry, honor it.
            if ($ticket->expired_at !== null) {
                return $ticket->expired_at->lt($now);
            }

            // Otherwise derive the deadline from the event end date + time.
            $event = $ticket->event;
            if (! $event) {
                return false;
            }

            $deadline = $this->eventEndTimestamp($event);

            return $deadline->lt($now);
        });

        if ($toExpire->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($toExpire, $now) {

            $ticketIds = $toExpire->pluck('id');

            Ticket::whereIn('id', $ticketIds)
                ->whereIn('status', [Ticket::ACTIVE, Ticket::DONE])
                ->update([
                    'status' => Ticket::EXPIRED,
                    'expired_at' => $now,
                    'updated_at' => $now,
                ]);

            $logs = $ticketIds
                ->map(function ($ticketId) use ($now) {
                    return [
                        'ticket_id' => $ticketId,
                        'action' => Ticket::EXPIRED,
                        'description' => 'Ticket expired automatically because its event has ended.',
                        'metadata' => json_encode(['source' => 'scheduled:expiry']),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                })
                ->toArray();

            TicketLog::insert($logs);

            return $ticketIds->count();
        });
    }

    /**
     * Build a Carbon timestamp from an event's end_date + end_time.
     */
    protected function eventEndTimestamp(Event $event): Carbon
    {
        return Carbon::parse($event->end_date->toDateString())
            ->setTimeFromTimeString((string) $event->end_time)
            ->utc();
    }

    /**
     * Build a Carbon timestamp from an event's start_date + start_time.
     */
    protected function eventStartTimestamp(Event $event): Carbon
    {
        return Carbon::parse($event->start_date->toDateString())
            ->setTimeFromTimeString((string) $event->start_time)
            ->utc();
    }

    /**
     * Check whether the current time is inside the event's open window
     * (start_date+start_time .. end_date+end_time). Used to prevent self
     * check-in before the doors open or after the event has wrapped up.
     */
    public function isInsideEventWindow(Ticket $ticket): bool
    {
        $event = $ticket->event;
        if (! $event) {
            return false;
        }

        $now = now('UTC');
        $open = $this->eventStartTimestamp($event);
        $close = $this->eventEndTimestamp($event);

        return $now->gte($open) && $now->lte($close);
    }

    /**
     * Human-readable window for error messages, e.g.
     * "Apr 12, 2026 5:00 PM - Apr 12, 2026 11:00 PM".
     */
    public function eventWindowLabel(Ticket $ticket): string
    {
        $event = $ticket->event;
        if (! $event) {
            return 'not scheduled';
        }

        $open = $this->eventStartTimestamp($event);
        $close = $this->eventEndTimestamp($event);

        return $open->format('M j, Y g:i A').' - '.$close->format('M j, Y g:i A');
    }

    /**
     * Check whether a single ticket should be treated as expired right now.
     * Used by the API for on-demand validation (belt and braces alongside the
     * scheduled command). Checks both ACTIVE and DONE tickets.
     */
    public function isExpired(Ticket $ticket): bool
    {
        if (! in_array($ticket->status, [Ticket::ACTIVE, Ticket::DONE], true)) {
            return false;
        }

        $deadline = $ticket->expired_at
            ?? $this->eventEndTimestamp($ticket->event);

        return $deadline->lt(now('UTC'));
    }
}
