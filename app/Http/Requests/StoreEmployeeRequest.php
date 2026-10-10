<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Employee::class) === true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'numeric', 'exists:users,id', Rule::unique('employees', 'user_id')],
            'position_id' => ['nullable', 'integer', 'numeric', 'exists:positions,id'],
            'supervisor_employee_id' => ['nullable', 'integer', 'numeric', 'exists:employees,id'],
            'employee_number' => ['required', 'string', 'max:50', Rule::unique('employees', 'employee_number')],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'gender' => ['nullable', 'string', 'max:30'],
            'civil_status' => ['nullable', 'string', 'max:30'],
            'personal_email' => ['nullable', 'email', 'max:255'],
            'company_email' => ['nullable', 'email', 'max:255', Rule::unique('employees', 'company_email')],
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
}
