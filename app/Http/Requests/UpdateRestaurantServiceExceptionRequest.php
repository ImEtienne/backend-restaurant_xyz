<?php

namespace App\Http\Requests;

use App\Models\RestaurantServiceException;
use Illuminate\Validation\Validator;

class UpdateRestaurantServiceExceptionRequest extends StoreRestaurantServiceExceptionRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['service_key'] = ['prohibited'];

        return $rules;
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['service_key', 'start_date', 'end_date', 'opens_at', 'closes_at', 'duration_minutes'])) {
                    return;
                }

                $exception = $this->route('exception');
                $exceptionId = $exception instanceof RestaurantServiceException ? $exception->id : (int) $exception;
                $serviceKey = $exception instanceof RestaurantServiceException
                    ? $exception->service_key
                    : RestaurantServiceException::query()->findOrFail($exceptionId)->service_key;
                $isClosed = $this->boolean('is_closed');
                if ($serviceKey === '*' && ! $isClosed) {
                    $validator->errors()->add('is_closed', 'Une fermeture globale doit rester fermee.');

                    return;
                }
                if (! $isClosed && $this->minutes($this->string('closes_at')->toString()) <= $this->minutes($this->string('opens_at')->toString())) {
                    $validator->errors()->add('closes_at', "L'heure de fermeture doit etre apres l'heure d'ouverture.");

                    return;
                }

                if (! $isClosed && (int) $this->input('duration_minutes') > $this->minutes($this->string('closes_at')->toString()) - $this->minutes($this->string('opens_at')->toString())) {
                    $validator->errors()->add('duration_minutes', "La duree ne peut pas depasser l'amplitude du service.");

                    return;
                }

                $overlappingExceptionExists = RestaurantServiceException::query()
                    ->where('service_key', $serviceKey)
                    ->where('id', '!=', $exceptionId)
                    ->where('start_date', '<=', $this->input('end_date'))
                    ->where('end_date', '>=', $this->input('start_date'))
                    ->exists();
                if ($overlappingExceptionExists) {
                    $validator->errors()->add('start_date', 'Cette periode chevauche une exception deja configuree pour ce service.');
                }
            },
        ];
    }

    protected function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return ($hours * 60) + $minutes;
    }
}
