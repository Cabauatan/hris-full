<?php

namespace App\Http\Requests;

use App\Models\EmployeeScheduleAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateEmployeeScheduleAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employeeScheduleAssignment = $this->route('employeeScheduleAssignment');

        return $employeeScheduleAssignment instanceof EmployeeScheduleAssignment
            && $this->user()?->can('update', $employeeScheduleAssignment) === true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'numeric', 'exists:employees,id'],
            'work_schedule_id' => ['required', 'integer', 'numeric', Rule::exists('work_schedules', 'id')->where('is_active', true)],
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

            $assignment = $this->route('employeeScheduleAssignment');
            $end = $this->exists('effective_to') ? $this->input('effective_to') : ($assignment instanceof EmployeeScheduleAssignment ? $assignment->effective_to : null);

            if ($end !== null && CarbonImmutable::parse($end)->lt(CarbonImmutable::parse($this->input('effective_from')))) {
                $validator->errors()->add('effective_to', 'The end date must be on or after the start date.');
            }
        }];
    }
}
