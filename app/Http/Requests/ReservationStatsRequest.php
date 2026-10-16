<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReservationStatsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-reservations') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $today = CarbonImmutable::now(config('reservations.timezone'));
        $this->mergeIfMissing([
            'start_date' => $today->subDays(29)->toDateString(),
            'end_date' => $today->toDateString(),
        ]);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'string', 'date_format:Y-m-d'],
            'end_date' => ['required', 'string', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $start = CarbonImmutable::parse($this->input('start_date'));
            $end = CarbonImmutable::parse($this->input('end_date'));

            if ($end->lessThan($start)) {
                $validator->errors()->add('end_date', 'La date de fin doit etre posterieure ou egale a la date de debut.');

                return;
            }

            if ($start->diffInDays($end) > 365) {
                $validator->errors()->add('end_date', 'La periode ne peut pas depasser 366 jours inclus.');
            }
        }];
    }
}
