<?php

namespace App\Http\Requests;

use App\Models\PayrollRun;
use Illuminate\Foundation\Http\FormRequest;

class StartPayrollRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        $payrollRun = $this->route('payrollRun');

        return $payrollRun instanceof PayrollRun
            && $this->user()?->can('process', $payrollRun) === true;
    }

    public function rules(): array
    {
        return [];
    }
}
