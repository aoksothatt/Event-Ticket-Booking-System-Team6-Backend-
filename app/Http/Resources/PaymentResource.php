<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'provider' => $this->provider,
            'transaction_reference' => $this->transaction_reference,
            'bakong_md5' => $this->bakong_md5,
            'bakong_transaction_id' => $this->bakong_transaction_id,
            'qr_payload' => $this->qr_payload,
            'deeplink' => $this->deeplink,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'status' => $this->status,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'booking' => $this->whenLoaded('booking', fn () => [
                'id' => $this->booking->id,
                'booking_number' => $this->booking->booking_number,
                'status' => $this->booking->status,
                'total_amount' => $this->booking->total_amount,
            ]),
        ];
    }
}
