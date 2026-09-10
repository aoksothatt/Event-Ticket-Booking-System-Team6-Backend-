<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketIssued extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public $tickets
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Tickets for ' . ($this->booking->event?->title ?? 'the event'),
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.TicketIssued',
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return collect($this->tickets)
            ->map(fn ($ticket) => $ticket->qr_code
                ? Attachment::fromStorageDisk('public', $ticket->qr_code)
                    ->as('ticket-' . $ticket->ticket_number . '.png')
                    ->withMime('image/png')
                : null)
            ->filter()
            ->values()
            ->all();
    }
}