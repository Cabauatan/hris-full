<?php

namespace App\Http\Requests;

use App\Models\EmployeeEmploymentHistory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateEmployeeEmploymentHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employeeEmploymentHistory = $this->route('employeeEmploymentHistory');

        return $employeeEmploymentHistory instanceof EmployeeEmploymentHistory
            && $this->user()?->can('update', $employeeEmploymentHistory) === true;
    }

    public function rules(): array
    {
        return [
            'position_id' => ['nullable', 'integer', 'numeric', 'exists:positions,id'],
            'supervisor_employee_id' => ['nullable', 'integer', 'numeric', 'exists:employees,id'],
            'employment_type' => ['required', 'string', 'max:50'],
            'employment_status' => ['required', 'string', 'max:50'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', Rule::when(is_string($this->input('effective_from')), 'after_or_equal:effective_from')],
            'change_type' => ['required', 'string', 'max:50'],
            'reason' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['employee_id', 'supervisor_employee_id'])) {
                return;
            }

            $history = $this->route('employeeEmploymentHistory');
            $employeeId = $history instanceof EmployeeEmploymentHistory ? $history->employee_id : null;

            if ($employeeId !== null && (int) $this->input('supervisor_employee_id') === (int) $employeeId) {
                $validator->errors()->add('supervisor_employee_id', 'An employee cannot supervise themselves.');
            }
        }, function (Validator $validator): void {
            if ($validator->errors()->hasAny(['effective_from', 'effective_to'])) {
                return;
            }

            $history = $this->route('employeeEmploymentHistory');
            $end = $this->exists('effective_to') ? $this->input('effective_to') : ($history instanceof EmployeeEmploymentHistory ? $history->effective_to : null);

            if ($end !== null && CarbonImmutable::parse($end)->lt(CarbonImmutable::parse($this->input('effective_from')))) {
                $validator->errors()->add('effective_to', 'The end date must be on or after the start date.');
            }
        }];
    }
}
