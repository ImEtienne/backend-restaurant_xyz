<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReservationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-reservations') ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['status' => ['required', 'string', 'in:confirmed,rejected']];
    }
}
