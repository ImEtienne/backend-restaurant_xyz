<?php

namespace App\Http\Requests;

use App\Models\RestaurantServiceException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRestaurantServiceExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-reservations') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $isClosed = $this->boolean('is_closed');
        $isGlobalClosure = $this->input('service_key') === '*';

        return [
            'service_key' => ['required', 'string', 'regex:/\A(?:[a-z0-9][a-z0-9_-]{0,63}|\*)\z/'],
            'service_name' => [
                Rule::requiredIf(! $isClosed && ! $isGlobalClosure),
                Rule::prohibitedIf($isGlobalClosure),
                'nullable',
                'string',
                'max:100',
            ],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'is_closed' => ['required', 'boolean'],
            'opens_at' => [Rule::requiredIf(! $isClosed && ! $isGlobalClosure), Rule::prohibitedIf($isClosed || $isGlobalClosure), 'date_format:H:i'],
            'closes_at' => [Rule::requiredIf(! $isClosed && ! $isGlobalClosure), Rule::prohibitedIf($isClosed || $isGlobalClosure), 'date_format:H:i'],
            'slot_interval_minutes' => [Rule::requiredIf(! $isClosed && ! $isGlobalClosure), Rule::prohibitedIf($isClosed || $isGlobalClosure), 'integer', 'between:5,240'],
            'duration_minutes' => [Rule::requiredIf(! $isClosed && ! $isGlobalClosure), Rule::prohibitedIf($isClosed || $isGlobalClosure), 'integer', 'between:15,480'],
            'capacity_guests' => [Rule::requiredIf(! $isClosed && ! $isGlobalClosure), Rule::prohibitedIf($isClosed || $isGlobalClosure), 'integer', 'between:1,500'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['service_key', 'start_date', 'end_date', 'opens_at', 'closes_at', 'duration_minutes'])) {
                    return;
                }

                $isClosed = $this->boolean('is_closed');
                $serviceKey = $this->string('service_key')->toString();
                if ($serviceKey === '*' && ! $isClosed) {
                    $validator->errors()->add('service_key', 'Une fermeture globale doit etre definie comme fermee.');

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
