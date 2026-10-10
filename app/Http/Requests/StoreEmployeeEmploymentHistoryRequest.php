<?php

namespace App\Http\Requests;

use App\Models\EmployeeEmploymentHistory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEmployeeEmploymentHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', EmployeeEmploymentHistory::class) === true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'numeric', 'exists:employees,id'],
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
            $employeeId = $this->input('employee_id');

            if ($employeeId !== null && (int) $this->input('supervisor_employee_id') === (int) $employeeId) {
                $validator->errors()->add('supervisor_employee_id', 'An employee cannot supervise themselves.');
            }
        }];
    }
}
