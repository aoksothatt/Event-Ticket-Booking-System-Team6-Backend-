<?php

namespace App\Http\Requests\CheckIn;

use Illuminate\Foundation\Http\FormRequest;

class CheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'ticket_code' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:150'],
        ];
    }
}
