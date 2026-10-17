<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'email' => ['bail', 'required', 'string', 'email', 'max:254', Rule::unique(User::class)],
            'password' => ['required', 'string', 'min:12', 'max:1024', 'confirmed'],
            'registration_token' => ['required', 'string', 'size:64'],
            'is_admin' => ['prohibited'],
            'is_active' => ['prohibited'],
            'role' => ['prohibited'],
        ];
    }
}
