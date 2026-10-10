<?php

namespace App\Http\Requests;

use App\Models\PayrollRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePayrollRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PayrollRun::class) === true;
    }

    public function rules(): array
    {
        return [
            'payroll_period_id' => ['required', 'integer', 'numeric', 'exists:payroll_periods,id'],
            'run_number' => ['required', 'integer', 'numeric', 'between:1,4294967295', Rule::unique('payroll_runs', 'run_number')->where('payroll_period_id', is_numeric($this->input('payroll_period_id')) ? (int) $this->input('payroll_period_id') : null)],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
