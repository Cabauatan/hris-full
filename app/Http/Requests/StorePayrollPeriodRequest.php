<?php

namespace App\Http\Requests;

use App\Models\PayrollPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePayrollPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PayrollPeriod::class) === true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('payroll_periods', 'code')],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', Rule::when(is_string($this->input('period_start')), 'after_or_equal:period_start')],
            'pay_date' => ['required', 'date_format:Y-m-d'],
            'pay_frequency' => ['required', 'string', 'max:30'],
            'status' => ['sometimes', 'string', 'max:30'],
        ];
    }
}
