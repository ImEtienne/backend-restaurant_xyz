<?php

namespace App\Http\Requests;

use App\Models\Reservation;

class UpdateClientReservationRequest extends StoreReservationRequest
{
    protected function prepareForValidation(): void
    {
        $reservation = Reservation::query()->where('user_id', $this->user()->id)->findOrFail($this->route('reservation'));
        $this->mergeIfMissing([
            'name' => $reservation->name, 'phone' => $reservation->phone,
            'date' => $reservation->date, 'time' => substr($reservation->time, 0, 5), 'guests' => $reservation->guests,
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['email'] = ['prohibited'];
        $rules['status'] = ['prohibited'];
        $rules['user_id'] = ['prohibited'];

        return $rules;
    }
}
