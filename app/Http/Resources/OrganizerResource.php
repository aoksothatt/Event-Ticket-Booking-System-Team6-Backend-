<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_name' => $this->company_name,
            'company_logo' => $this->company_logo,
            'phone' => $this->phone,
            'website' => $this->website,
            'description' => $this->description,
            'is_verified' => $this->is_verified,
            'user' => $this->whenLoaded('user', fn () => UserResource::make($this->user)),
            'events_count' => $this->whenCounted('events'),
            'created_at' => $this->created_at,
        ];
    }
}
