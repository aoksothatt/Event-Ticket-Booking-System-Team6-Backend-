<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketCheckin;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class OrganizerDashboardService
{
    /**
     * Aggregate metrics for a single organizer's dashboard.
     *
     * @return array{
     *     tickets_sold: int,
     *     tickets_checked_in: int,
     *     remaining_tickets: int,
     *     recent_checkins: Collection,
     *     today_attendance: int,
     *     total_events: int,
     *     total_revenue: string,
     * }
     */
    public function stats(int $organizerId): array
    {
        $ticketsSold = Ticket::query()
            ->whereHas('event', fn ($q) => $q->where('organizer_id', $organizerId))
            ->where('status', '!=', TicketStatus::CANCELLED->value)
            ->count();

        $ticketsCheckedIn = TicketCheckin::query()
            ->where('organizer_id', $organizerId)
            ->count();

        $ticketsAvailable = TicketType::query()
            ->whereHas('event', fn ($q) => $q->where('organizer_id', $organizerId))
            ->where('status', '!=', 'sold_out')
            ->get()
            ->sum(fn (TicketType $type) => $type->quantity - $type->sold_quantity);

        $todayAttendance = TicketCheckin::query()
            ->where('organizer_id', $organizerId)
            ->whereDate('checked_in_at', today())
            ->count();

        $revenue = Ticket::query()
            ->whereHas('event', fn ($q) => $q->where('organizer_id', $organizerId))
            ->with('ticketType')
            ->get()
            ->sum(fn (Ticket $ticket) => (float) ($ticket->ticketType?->price ?? 0));

        $recentCheckins = TicketCheckin::query()
            ->with(['ticket' => fn ($q) => $q->with(['user', 'ticketType']), 'event', 'staff'])
            ->where('organizer_id', $organizerId)
            ->latest('checked_in_at')
            ->limit(10)
            ->get();

        return [
            'tickets_sold' => $ticketsSold,
            'tickets_checked_in' => $ticketsCheckedIn,
            'remaining_tickets' => max(0, $ticketsAvailable),
            'recent_checkins' => $recentCheckins,
            'today_attendance' => $todayAttendance,
            'total_events' => $this->totalEvents($organizerId),
            'total_revenue' => number_format($revenue, 2, '.', ''),
        ];
    }

    /**
     * Attendance statistics for a single event, usable by its organizer
     * and the organizer's event staff.
     */
    public function eventAttendance(int $organizerId, int $eventId): array
    {
        $baseQuery = Ticket::query()
            ->where('event_id', $eventId)
            ->whereHas('event', fn ($q) => $q->where('organizer_id', $organizerId));

        return [
            'total_tickets' => $baseQuery->count(),
            'checked_in' => (clone $baseQuery)->where('status', TicketStatus::USED->value)->count(),
            'remaining' => max(0, (clone $baseQuery)->where('status', TicketStatus::ACTIVE->value)->count()),
            'cancelled' => (clone $baseQuery)->where('status', TicketStatus::CANCELLED->value)->count(),
            'expired' => (clone $baseQuery)->where('status', TicketStatus::EXPIRED->value)->count(),
            'today_attendance' => TicketCheckin::query()
                ->where('event_id', $eventId)
                ->where('organizer_id', $organizerId)
                ->whereDate('checked_in_at', today())
                ->count(),
        ];
    }

    protected function totalEvents(int $organizerId): int
    {
        return DB::table('events')
            ->where('organizer_id', $organizerId)
            ->count();
    }
}
