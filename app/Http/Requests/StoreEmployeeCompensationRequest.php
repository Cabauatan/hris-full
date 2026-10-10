<?php

namespace App\Http\Requests;

use App\Models\EmployeeCompensation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeCompensationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', EmployeeCompensation::class) === true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'numeric', 'exists:employees,id'],
            'basic_salary' => ['required', 'numeric', 'regex:/^\d+(?:\.\d{1,2})?$/', 'between:0,9999999999999.99'],
            'pay_frequency' => ['required', 'string', 'max:30'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', Rule::when(is_string($this->input('effective_from')), 'after_or_equal:effective_from')],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
