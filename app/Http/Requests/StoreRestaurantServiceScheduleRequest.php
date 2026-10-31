<?php

namespace App\Http\Requests;

use App\Models\RestaurantServiceSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRestaurantServiceScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-reservations') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'service_key' => ['required', 'string', 'regex:/\A[a-z0-9][a-z0-9_-]{0,63}\z/', Rule::unique(RestaurantServiceSchedule::class, 'service_key')->where('day_of_week', $this->input('day_of_week'))],
            'service_name' => ['required', 'string', 'max:100'],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'opens_at' => ['required', 'date_format:H:i'],
            'closes_at' => ['required', 'date_format:H:i'],
            'slot_interval_minutes' => ['required', 'integer', 'between:5,240'],
            'duration_minutes' => ['required', 'integer', 'between:15,480'],
            'capacity_guests' => ['required', 'integer', 'between:1,500'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['opens_at', 'closes_at', 'duration_minutes'])) {
                    return;
                }

                $opensAt = $this->minutes($this->string('opens_at')->toString());
                $closesAt = $this->minutes($this->string('closes_at')->toString());
                if ($closesAt <= $opensAt) {
                    $validator->errors()->add('closes_at', "L'heure de fermeture doit etre apres l'heure d'ouverture.");

                    return;
                }

                if ((int) $this->input('duration_minutes') > $closesAt - $opensAt) {
                    $validator->errors()->add('duration_minutes', "La duree ne peut pas depasser l'amplitude du service.");
                }
            },
        ];
    }

    private function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return ($hours * 60) + $minutes;
    }
}
