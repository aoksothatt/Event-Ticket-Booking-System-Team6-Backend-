<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Ticket;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Storage;
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
        $ticketNumber = $this->uniqueTicketNumber();
        $qrToken = $this->uniqueQrToken();

        $ticket = Ticket::create([
            'booking_id'      => $booking->id,
            'booking_item_id' => $item->id,
            'ticket_type_id'  => $item->ticket_type_id,
            'user_id'         => $booking->user_id,
            'ticket_code'     => $this->uniqueTicketCode(),
            'ticket_number'   => $ticketNumber,
            'qr_token'        => $qrToken,
            'status'          => 'active',
            'issued_at'       => now(),
            'used_at'         => null,
        ]);

        $this->attachQrImage($ticket);

        return $ticket;
    }

    /**
     * Render and store the ticket's QR code image (public disk) containing
     * the verifiable data encoded for the check-in scanner.
     */
    protected function attachQrImage(Ticket $ticket): void
    {
        try {
            $payload = json_encode([
                'ticket_id' => $ticket->id,
                'booking_id' => $ticket->booking_id,
                'event_id' => $ticket->booking?->event_id,
                'security_hash' => $ticket->qr_token,
            ]);

            $result = Builder::create()
                ->writer(new PngWriter())
                ->data((string) $payload)
                ->encoding(new Encoding('UTF-8'))
                ->errorCorrectionLevel(ErrorCorrectionLevel::High)
                ->size(300)
                ->margin(10)
                ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
                ->build();

            $path = 'tickets/qr-' . $ticket->qr_token . '.png';
            Storage::disk('public')->put($path, $result->getString());

            $ticket->update(['qr_code' => $path]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Could not generate ticket QR image.', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generate a unique sequential, human-readable ticket number.
     *
     * Example: EVT-2026-000042
     */
    protected function uniqueTicketNumber(): string
    {
        $year = now()->year;

        do {
            $sequence = Ticket::max('id') + 1;
            $number = sprintf('EVT-%d-%06d', $year, $sequence);
        } while (Ticket::where('ticket_number', $number)->exists());

        return $number;
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