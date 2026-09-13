<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketService
{
    /**
     * Generate one actual Ticket record per purchased quantity for a booking.
     *
     * Called only after a payment is confirmed. Idempotent: if the booking
     * already has tickets it will never create duplicates.
     *
     * @return Collection
     */
    public function generateForBooking(Booking $booking)
    {
        $booking->loadMissing(['items.ticketType.event', 'user']);

        $needed = (int) $booking->items->sum('quantity');
        $existing = (int) $booking->tickets()->count();

        if ($existing >= $needed) {
            return $booking->tickets()->get();
        }

        $created = DB::transaction(function () use ($booking) {
            $created = collect();
            $sequence = 0;

            foreach ($booking->items as $item) {
                $itemExisting = $item->tickets()->count();
                $toCreate = max(0, (int) $item->quantity - $itemExisting);

                for ($i = 0; $i < $toCreate; $i++) {
                    $sequence++;
                    $created->push($this->createForItem($booking, $item, $sequence));
                }
            }

            return $created;
        });

        return $created->isEmpty() ? $booking->tickets()->get() : $created;
    }

    /**
     * Create a single ticket record for a booking item inside the transaction.
     */
    protected function createForItem(Booking $booking, $item, int $sequence): Ticket
    {
        $ticketType = $item->ticketType;
        $event = $ticketType->event;

        $ticket = Ticket::create([
            'booking_id' => $booking->id,
            'booking_item_id' => $item->id,
            'ticket_type_id' => $ticketType->id,
            'user_id' => $booking->user_id,
            'event_id' => $event->id,
            'ticket_code' => $this->uniqueTicketCode(),
            'ticket_number' => 'TKT-' . $booking->id . '-' . $sequence,
            'qr_token' => $this->uniqueQrToken(),
            'status' => Ticket::ACTIVE,
            'issued_at' => now(),
            'expired_at' => $this->eventEndTimestamp($event),
            'used_at' => null,
        ]);

        // Initial creation is part of the audit trail too.
        $ticket->logs()->create([
            'action' => 'CREATED',
            'description' => 'Ticket issued for booking '.$booking->booking_number,
            'actor_id' => auth()->id(),
            'metadata' => [
                'booking_id' => $booking->id,
                'booking_number' => $booking->booking_number,
                'ticket_type' => $ticketType->name,
                'ticket_code' => $ticket->ticket_code,
            ],
        ]);

        return $ticket;
    }

    /**
     * Generate the event's end timestamp (end_date + end_time) to use as the
     * ticket's natural expiry.
     */
    protected function eventEndTimestamp($event): Carbon
    {
        return Carbon::parse($event->end_date->toDateString())
            ->setTimeFromTimeString((string) $event->end_time)
            ->utc();
    }

    /**
     * Generate a unique, human-readable ticket code like TKT-8K2X9P.
     */
    protected function uniqueTicketCode()
    {
        do {
            $code = 'TKT-'.strtoupper(Str::random(6));
        } while (Ticket::where('ticket_code', $code)->exists());

        return $code;
    }

    /**
     * Generate a secure, unique UUID v4 QR token. Internal sequential IDs are
     * never exposed — the QR code encodes only this token.
     */
    protected function uniqueQrToken()
    {
        do {
            $token = (string) Str::uuid();
        } while (Ticket::where('qr_token', $token)->exists());

        return $token;
    }
}
