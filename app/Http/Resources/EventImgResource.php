<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class EventImgResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'image' => $this->image,
            'sort_order' => $this->sort_order,
            'is_primary' => $this->sort_order === 1,
            'url' => $this->image
                ? Storage::disk('public')->url($this->image)
                : null,
        ];
    }
}