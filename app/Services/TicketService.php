<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Ticket;
use Illuminate\Support\Str;

class TicketService
{
    /**
     * Generate one actual Ticket record per purchased quantity for a booking.
     *
     * Called only after a payment is confirmed. This method is idempotent:
     * if the booking already has tickets for the count of its confirmed items,
     * no duplicates are created.
     *
     * @return \Illuminate\Support\Collection
     */
    public function generateForBooking(Booking $booking)
    {
        // Refresh the bookings items + quantities to know how many to issue.
        $booking->loadMissing(['items', 'user']);

        $needed = (int) $booking->items->sum('quantity');
        $existing = (int) $booking->tickets()->count();

        if ($existing >= $needed) {
            return $booking->tickets()->get();
        }

        $created = collect();

        foreach ($booking->items as $item) {
            $itemExisting = $item->tickets()->count();
            $toCreate = max(0, (int) $item->quantity - $itemExisting);

            for ($i = 0; $i < $toCreate; $i++) {
                $created->push($this->createForItem($booking, $item));
            }
        }

        return $created->isEmpty() ? $booking->tickets()->get() : $created;
    }

    /**
     * Create a single ticket for a booking item.
     */
    protected function createForItem(Booking $booking, $item)
    {
        return Ticket::create([
            'booking_id'      => $booking->id,
            'booking_item_id' => $item->id,
            'ticket_type_id'  => $item->ticket_type_id,
            'user_id'         => $booking->user_id,
            'ticket_code'     => $this->uniqueTicketCode(),
            'qr_token'        => $this->uniqueQrToken(),
            'status'          => 'active',
            'used_at'         => null,
        ]);
    }

    /**
     * Generate a unique, human-readable ticket code like TKT-8K2X9P.
     */
    protected function uniqueTicketCode()
    {
        do {
            $code = 'TKT-' . strtoupper(Str::random(6));
        } while (Ticket::where('ticket_code', $code)->exists());

        return $code;
    }

    /**
     * Generate a secure, unique QR token that encodes a verification URL.
     * No personal/sensitive data is embedded.
     */
    protected function uniqueQrToken()
    {
        do {
            $token = Str::random(32);
        } while (Ticket::where('qr_token', $token)->exists());

        return $token;
    }
}