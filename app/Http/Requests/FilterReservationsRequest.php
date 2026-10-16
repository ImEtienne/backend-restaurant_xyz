<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FilterReservationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-reservations') ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'string', 'in:pending,confirmed,rejected'],
        ];
    }
}
