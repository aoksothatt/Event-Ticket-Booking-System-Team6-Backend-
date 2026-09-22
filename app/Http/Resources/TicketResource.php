<?php

namespace App\Http\Resources;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // QR tokens are only disclosed when the platform has QR codes enabled
        // (ticket.qr_enabled). Disabling QRs hides every token — the customer UI
        // then falls back to the plain ticket_code (e.g. a manual "tap to
        // check-in" flow) and no scannable QR is ever rendered.
        $qrEnabled = (bool) Setting::value('ticket.qr_enabled', true);

        $canViewQr = $qrEnabled
            && ($request->user()?->id === $this->user_id
                || in_array($request->user()?->role, ['admin', 'organizer', 'event_staff'], true));

        return [
            'id' => $this->id,
            'ticket_code' => $this->ticket_code,
            'qr_token' => $canViewQr ? $this->qr_token : null,
            'status' => $this->status,
            'status_label' => $this->status_label,
            'used_at' => $this->used_at,
            'expired_at' => $this->expired_at,
            'is_valid' => $this->is_valid,
            'user' => $this->whenLoaded('user', fn () => UserResource::make($this->user)),
            'event' => $this->whenLoaded('event', fn () => EventResource::make($this->event)),
            'ticket_type' => $this->whenLoaded('ticketType', fn () => TicketTypeResource::make($this->ticketType)),
            'booking' => $this->whenLoaded('booking', fn () => BookingResource::make($this->booking)),
            'checkins' => $this->whenLoaded('ticketCheckins', fn () => CheckInResource::collection($this->ticketCheckins)),
            'created_at' => $this->created_at,
        ];
    }
}
