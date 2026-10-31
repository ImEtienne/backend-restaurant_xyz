<?php

namespace App\Http\Requests;

class UpdateRestaurantServiceScheduleRequest extends StoreRestaurantServiceScheduleRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['service_key'], $rules['day_of_week']);

        return $rules + [
            'service_key' => ['prohibited'],
            'day_of_week' => ['prohibited'],
        ];
    }
}
