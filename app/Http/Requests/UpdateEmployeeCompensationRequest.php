<?php

namespace App\Http\Requests;

use App\Models\EmployeeCompensation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateEmployeeCompensationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employeeCompensation = $this->route('employeeCompensation');

        return $employeeCompensation instanceof EmployeeCompensation
            && $this->user()?->can('update', $employeeCompensation) === true;
    }

    public function rules(): array
    {
        return [
            'basic_salary' => ['required', 'numeric', 'regex:/^\d+(?:\.\d{1,2})?$/', 'between:0,9999999999999.99'],
            'pay_frequency' => ['required', 'string', 'max:30'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', Rule::when(is_string($this->input('effective_from')), 'after_or_equal:effective_from')],
            'remarks' => ['nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['effective_from', 'effective_to'])) {
                return;
            }

            $compensation = $this->route('employeeCompensation');
            $end = $this->exists('effective_to') ? $this->input('effective_to') : ($compensation instanceof EmployeeCompensation ? $compensation->effective_to : null);

            if ($end !== null && CarbonImmutable::parse($end)->lt(CarbonImmutable::parse($this->input('effective_from')))) {
                $validator->errors()->add('effective_to', 'The end date must be on or after the start date.');
            }
        }];
    }
}
