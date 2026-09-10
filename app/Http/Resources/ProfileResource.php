<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'phone' => $this->phone,
            'gender' => $this->gender,
            'dob' => $this->dob,
            'address' => $this->address,
            'avatar' => $this->avatar,
        ];
    }
}
