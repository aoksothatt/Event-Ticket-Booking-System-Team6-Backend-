<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'banner' => $this->banner,
            'status' => $this->status,
            'is_trending' => $this->is_trending,
            'venue' => $this->whenLoaded('venue', fn () => VenueResource::make($this->venue)),
            'category' => $this->whenLoaded('category', fn () => CategoryResource::make($this->category)),
            'organizer' => $this->whenLoaded('organizer', fn () => OrganizerResource::make($this->organizer)),
            'ticket_types' => TicketTypeResource::collection($this->whenLoaded('ticketTypes')),
            'images' => $this->whenLoaded('images'),
            'created_at' => $this->created_at,
        ];
    }
}
