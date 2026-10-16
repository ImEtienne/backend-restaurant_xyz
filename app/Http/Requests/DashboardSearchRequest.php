<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DashboardSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('manage-users') ?? false) && ($this->user()?->can('manage-reservations') ?? false);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'q' => ['bail', 'required', 'string', 'min:2', 'max:100'],
            'limit' => ['sometimes', 'required', 'integer', 'between:1,20'],
        ];
    }
}
