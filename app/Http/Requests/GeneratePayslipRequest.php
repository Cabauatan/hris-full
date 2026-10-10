<?php

namespace App\Http\Requests;

use App\Models\PayrollEntry;
use App\Models\Payslip;
use Illuminate\Foundation\Http\FormRequest;

class GeneratePayslipRequest extends FormRequest
{
    public function authorize(): bool
    {
        $payrollEntry = $this->route('payrollEntry');

        return $payrollEntry instanceof PayrollEntry
            && $this->user()?->can('generate', Payslip::class) === true;
    }

    public function rules(): array
    {
        return [];
    }
}
