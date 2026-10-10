<?php

namespace App\Http\Requests;

use App\Models\LeaveBalance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustLeaveCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('adjust', LeaveBalance::class) === true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'numeric', 'exists:employees,id'],
            'leave_type_id' => [
                'required',
                'integer',
                'numeric',
                Rule::exists('leave_types', 'id')->where('requires_balance', true),
            ],
            'units' => [
                'required',
                'numeric',
                'regex:/^-?\d+(?:\.\d{1,2})?$/',
                'not_regex:/^-?0+(?:\.0{1,2})?$/',
                'between:-99999999.99,99999999.99',
            ],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
