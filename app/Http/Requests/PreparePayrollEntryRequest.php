<?php

namespace App\Http\Requests;

use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use Illuminate\Foundation\Http\FormRequest;

class PreparePayrollEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $payrollRun = $this->route('payrollRun');

        return $payrollRun instanceof PayrollRun
            && $this->user()?->can('process', $payrollRun) === true
            && $this->user()?->can('process', PayrollEntry::class) === true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'numeric', 'exists:employees,id'],
            'compensation_reference_date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
