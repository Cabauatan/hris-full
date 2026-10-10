<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class WorkspaceDateRangeRequest extends FormRequest
{
    protected function canUseScope(): bool
    {
        return $this->input('scope') !== 'mine' || $this->user()?->employee()->exists() === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'start' => ['required_with:end', 'date_format:Y-m-d'],
            'end' => ['required_with:start', 'date_format:Y-m-d'],
            'scope' => ['sometimes', Rule::in(['mine', 'visible'])],
            'employee_id' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['start', 'end']) || ! $this->has(['start', 'end'])) {
                return;
            }

            $start = CarbonImmutable::parse($this->input('start'), config('app.timezone'));
            $end = CarbonImmutable::parse($this->input('end'), config('app.timezone'));

            if ($end->lessThan($start)) {
                $validator->errors()->add('end', 'The end date must be on or after the start date.');

                return;
            }

            if ($start->diffInDays($end) + 1 > $this->maximumDays()) {
                $validator->errors()->add('end', 'The selected date range may not exceed '.$this->maximumDays().' days.');
            }
        }];
    }

    protected function maximumDays(): int
    {
        return 366;
    }
}
