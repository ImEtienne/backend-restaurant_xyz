<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:100'],
            'email' => ['bail', 'required', 'string', 'max:254', 'email:rfc'],
            'phone' => ['bail', 'required', 'string', 'min:6', 'max:32'],
            'date' => ['bail', 'required', 'string', 'date_format:Y-m-d', 'after_or_equal:'.CarbonImmutable::now(config('reservations.timezone'))->toDateString()],
            'time' => ['bail', 'required', 'string', 'date_format:H:i'],
            'guests' => ['bail', 'required', 'integer', 'between:1,10'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['date', 'time'])) {
                    return;
                }

                $localDateTime = $this->input('date').' '.$this->input('time');
                $timezone = config('reservations.timezone');
                $reservationTime = CarbonImmutable::createFromFormat('!Y-m-d H:i', $localDateTime, $timezone);

                if ($reservationTime->format('Y-m-d H:i') !== $localDateTime) {
                    $validator->errors()->add('time', 'Cette heure locale est invalide pour le fuseau du restaurant.');

                    return;
                }

                if ($reservationTime->lessThanOrEqualTo(CarbonImmutable::now($timezone))) {
                    $validator->errors()->add('time', 'La date et l\'heure de reservation doivent etre dans le futur.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Le champ :attribute est obligatoire.',
            'string' => 'Le champ :attribute doit etre une chaine de caracteres.',
            'max' => 'Le champ :attribute ne doit pas depasser :max caracteres.',
            'min' => 'Le champ :attribute doit contenir au moins :min caracteres.',
            'email.email' => 'Le champ email doit etre une adresse email valide.',
            'date.date_format' => 'Le champ date doit respecter le format Y-m-d.',
            'date.after_or_equal' => 'La date de reservation ne peut pas etre passee.',
            'time.date_format' => 'Le champ time doit respecter le format H:i.',
            'guests.integer' => 'Le nombre de personnes doit etre un entier.',
            'guests.between' => 'Le nombre de personnes doit etre compris entre 1 et 10.',
        ];
    }
}