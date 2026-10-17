<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserResource extends JsonResource
{
    /** @return array{id: int, name: string, email: string, is_admin: bool, role: string, is_active: bool} */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_admin' => $this->is_admin === true,
            'role' => $this->is_admin === true ? 'admin' : 'client',
            'is_active' => $this->is_admin === true || $this->is_active === true,
        ];
    }
}
