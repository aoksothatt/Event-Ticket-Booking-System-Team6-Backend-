<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // The store flow creates the booking itself for the authenticated user,
        // so any authenticated user may create a checkout.
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The primary shape is a single checkout call carrying every selected
     * ticket type:
     *
     *   { "event_id": 1, "items": [{ "ticket_type_id": 1, "quantity": 2 }, ...] }
     *
     * For backwards compatibility a single "ticket_type_id" + "quantity"
     * pair is still accepted (mapped to a one-item order).
     *
     * A "booking_id" may be supplied to REUSE an existing pending booking
     * (e.g. retrying an expired/failed payment). In that case the backend
     * creates a fresh payment with a new reference instead of creating a
     * brand-new booking.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event_id' => 'required|integer|exists:events,id',

            'items' => 'sometimes|array|min:1',
            'items.*.ticket_type_id' => 'required|integer|distinct|exists:ticket_types,id',
            'items.*.quantity' => 'required|integer|min:1|max:10',

            // Legacy single-item shape.
            'ticket_type_id' => 'sometimes|integer|exists:ticket_types,id',
            'quantity' => 'sometimes|integer|min:1|max:10',

            // Optional: reuse an existing pending booking (retry a payment).
            'booking_id' => 'sometimes|nullable|integer|exists:Booking,id',
        ];
    }

    /**
     * Normalise the request into a list of {ticket_type_id, quantity} items
     * regardless of whether the caller used the modern "items" shape or the
     * legacy single ticket_type_id/quantity shape.
     *
     * @return array<int, array{ticket_type_id: int, quantity: int}>
     */
    public function items(): array
    {
        $validated = $this->validated();

        if (isset($validated['items']) && is_array($validated['items']) && $validated['items'] !== []) {
            return array_map(
                static fn (array $item) => [
                    'ticket_type_id' => (int) $item['ticket_type_id'],
                    'quantity' => (int) $item['quantity'],
                ],
                $validated['items']
            );
        }

        if (isset($validated['ticket_type_id'])) {
            return [[
                'ticket_type_id' => (int) $validated['ticket_type_id'],
                'quantity' => (int) ($validated['quantity'] ?? 1),
            ]];
        }

        return [];
    }

    public function messages(): array
    {
        return [
            'event_id.exists' => 'The selected event does not exist.',
            'items.*.ticket_type_id.exists' => 'The selected ticket type does not exist.',
            'items.*.ticket_type_id.distinct' => 'Each ticket type may only be selected once per order.',
            'items.*.quantity.min' => 'You must purchase at least one ticket.',
            'items.*.quantity.max' => 'You cannot purchase more than 10 tickets of a type in a single order.',
            'ticket_type_id.exists' => 'The selected ticket type does not exist.',
            'quantity.min' => 'You must purchase at least one ticket.',
            'quantity.max' => 'You cannot purchase more than 10 tickets in a single order.',
            'items.min' => 'Please select at least one ticket.',
        ];
    }
}