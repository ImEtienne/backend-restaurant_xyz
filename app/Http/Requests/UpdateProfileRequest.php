<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'sometimes', 'required', 'string', 'max:255'],
            'email' => ['bail', 'sometimes', 'required', 'string', 'email', 'max:254', Rule::unique(User::class)->ignore($this->user())],
            'password' => ['sometimes', 'required', 'string', 'min:12', 'max:1024', 'confirmed'],
            'current_password' => [Rule::requiredIf(fn (): bool => $this->has('password') || ($this->has('email') && $this->input('email') !== $this->user()->email)), 'string', 'current_password:web'],
            'is_admin' => ['prohibited'],
            'is_active' => ['prohibited'],
            'role' => ['prohibited'],
        ];
    }
}
