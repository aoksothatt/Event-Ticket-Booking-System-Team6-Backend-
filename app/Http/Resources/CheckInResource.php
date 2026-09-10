<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckInResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'event_id' => $this->event_id,
            'staff_id' => $this->staff_id,
            'organizer_id' => $this->organizer_id,
            'checked_in_at' => $this->checked_in_at,
            'device_name' => $this->device_name,
            'ip_address' => $this->ip_address,
            'source' => $this->source,
            'ticket' => $this->whenLoaded('ticket', fn () => TicketResource::make($this->ticket)),
            'event' => $this->whenLoaded('event', fn () => EventResource::make($this->event)),
            'staff' => $this->whenLoaded('staff', fn () => UserResource::make($this->staff)),
        ];
    }
}
