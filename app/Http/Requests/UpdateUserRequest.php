<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-users') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'sometimes', 'required', 'string', 'max:255'],
            'email' => ['bail', 'sometimes', 'required', 'string', 'email', 'max:254', Rule::unique(User::class)->ignore($this->route('user'))],
            'password' => ['sometimes', 'required', 'string', 'min:12', 'max:1024', 'confirmed'],
            'is_admin' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
