<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ActivateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-users') ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }
}
