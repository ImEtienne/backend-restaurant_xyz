<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminNotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            ...$this->data,
            'created_at' => $this->created_at?->toISOString(),
            'read_at' => $this->read_at?->toISOString(),
        ];
    }
}
