<?php

namespace App\Http\Requests;

class StoreClientReservationRequest extends StoreReservationRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['email' => $this->user()->email]);
    }
}
