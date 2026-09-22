<?php

namespace App\Services;

use App\Models\BookingItem;
use App\Models\TicketType;

/**
 * EventStatsService
 *
 * Computes the "Tickets Sold / Total Gross Revenue" dashboard numbers from
 * real booking data, shared by every consumer (admin event detail page and
 * any future exports).
 *
 * A booking only counts as a sale once it is actually paid — status is either
 * `confirmed` (payment verified to success) or `paid`. Pending, expired,
 * failed, cancelled, rejected and refunded bookings are ignored, so the
 * numbers never count unpaid reservations.
 *
 * Capacity is the total number of seats on sale across the event's ticket
 * types. Tickets sold and revenue are sums of the ORDER ITEMS (booking_items)
 * of successful bookings — never the `sold_quantity` reservation counter,
 * which tracks live inventory and therefore also counts unpaid holds.
 */
class EventStatsService
{
    /** @var list<string> */
    private const SUCCESS_STATUSES = ['confirmed', 'paid'];

    /**
     * @return array{capacity: int, tickets_sold: int, revenue: float}
     */
    public function forEvent(int $eventId): array
    {
        $capacity = (int) TicketType::where('event_id', $eventId)->sum('quantity');

        $aggregates = BookingItem::query()
            ->join('Booking as b', 'b.id', '=', 'booking_items.booking_id')
            ->where('b.event_id', $eventId)
            ->whereIn('b.status', self::SUCCESS_STATUSES)
            ->selectRaw('COALESCE(SUM(booking_items.quantity), 0) as tickets_sold')
            ->selectRaw('COALESCE(SUM(booking_items.subtotal), 0) as revenue')
            ->first();

        return [
            'capacity' => $capacity,
            'tickets_sold' => (int) $aggregates->tickets_sold,
            'revenue' => (float) $aggregates->revenue,
        ];
    }
}