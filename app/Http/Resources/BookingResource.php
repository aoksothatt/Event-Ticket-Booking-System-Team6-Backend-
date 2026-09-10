<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_number' => $this->booking_number,
            'booking_date' => $this->booking_date,
            'total_amount' => $this->total_amount,
            'status' => $this->status,
            'user' => $this->whenLoaded('user', fn () => UserResource::make($this->user)),
            'event' => $this->whenLoaded('event', fn () => EventResource::make($this->event)),
            'items' => $this->whenLoaded('items', fn () => BookingItemResource::collection($this->items)),
            'tickets' => $this->whenLoaded('tickets', fn () => TicketResource::collection($this->tickets)),
            'payments' => $this->whenLoaded('payments'),
            'created_at' => $this->created_at,
        ];
    }
}
