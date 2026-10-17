<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminReservationResource extends JsonResource
{
    /** @return array{id: int, user_id: ?int, name: string, email: string, phone: string, date: string, time: string, guests: int, status: string, created_at: ?string, updated_at: ?string} */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id === null ? null : (int) $this->user_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'date' => $this->date,
            'time' => substr($this->time, 0, 5),
            'guests' => $this->guests,
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
