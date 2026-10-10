<?php

namespace App\Http\Requests;

use App\Models\PayrollRun;
use Illuminate\Foundation\Http\FormRequest;

class FinalizePayrollRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        $payrollRun = $this->route('payrollRun');

        return $payrollRun instanceof PayrollRun
            && $this->user()?->can('finalize', $payrollRun) === true;
    }

    public function rules(): array
    {
        return [];
    }
}
