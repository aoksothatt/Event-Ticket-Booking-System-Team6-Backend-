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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event_id' => 'required|integer|exists:events,id',
            'ticket_type_id' => 'required|integer|exists:ticket_types,id',
            'quantity' => 'required|integer|min:1|max:10',
        ];
    }

    public function messages(): array
    {
        return [
            'event_id.exists' => 'The selected event does not exist.',
            'ticket_type_id.exists' => 'The selected ticket type does not exist.',
            'quantity.min' => 'You must purchase at least one ticket.',
            'quantity.max' => 'You cannot purchase more than 10 tickets in a single order.',
        ];
    }
}
