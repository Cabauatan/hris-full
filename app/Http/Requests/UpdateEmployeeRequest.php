<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee
            && $this->user()?->can('update', $employee) === true;
    }

    public function rules(): array
    {
        $employee = $this->route('employee');

        return [
            'user_id' => ['nullable', 'integer', 'numeric', 'exists:users,id', Rule::unique('employees', 'user_id')->ignore($employee instanceof Employee ? $employee : null)],
            'position_id' => ['nullable', 'integer', 'numeric', 'exists:positions,id'],
            'supervisor_employee_id' => ['nullable', 'integer', 'numeric', 'exists:employees,id'],
            'employee_number' => ['required', 'string', 'max:50', Rule::unique('employees', 'employee_number')->ignore($employee instanceof Employee ? $employee : null)],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'gender' => ['nullable', 'string', 'max:30'],
            'civil_status' => ['nullable', 'string', 'max:30'],
            'personal_email' => ['nullable', 'email', 'max:255'],
            'company_email' => ['nullable', 'email', 'max:255', Rule::unique('employees', 'company_email')->ignore($employee instanceof Employee ? $employee : null)],
            'mobile_number' => ['nullable', 'string', 'max:50'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['sometimes', 'string', 'max:100'],
            'hire_date' => ['required', 'date_format:Y-m-d'],
            'regularization_date' => ['nullable', 'date_format:Y-m-d', Rule::when(is_string($this->input('hire_date')), 'after_or_equal:hire_date')],
            'separation_date' => ['nullable', 'date_format:Y-m-d', Rule::when(is_string($this->input('hire_date')), 'after_or_equal:hire_date')],
            'employment_type' => ['required', 'string', 'max:50'],
            'employment_status' => ['sometimes', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $employee = $this->route('employee');

            if (! $validator->errors()->has('supervisor_employee_id')
                && $employee instanceof Employee
                && (int) $this->input('supervisor_employee_id') === $employee->id) {
                $validator->errors()->add('supervisor_employee_id', 'An employee cannot supervise themselves.');
            }

            if (! $employee instanceof Employee || $validator->errors()->has('hire_date')) {
                return;
            }

            foreach (['regularization_date', 'separation_date'] as $field) {
                if ($validator->errors()->has($field)) {
                    continue;
                }

                $end = $this->exists($field) ? $this->input($field) : $employee->getAttribute($field);

                if ($end !== null && CarbonImmutable::parse($end)->lt(CarbonImmutable::parse($this->input('hire_date')))) {
                    $validator->errors()->add($field, 'The date must be on or after the hire date.');
                }
            }
        }];
    }
}
