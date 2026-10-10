<?php

namespace App\Http\Requests;

use App\Models\EmployeeScheduleAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeScheduleAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', EmployeeScheduleAssignment::class) === true;
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
}
