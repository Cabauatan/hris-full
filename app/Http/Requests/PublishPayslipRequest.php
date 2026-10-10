<?php

namespace App\Http\Requests;

use App\Models\Payslip;
use Illuminate\Foundation\Http\FormRequest;

class PublishPayslipRequest extends FormRequest
{
    public function authorize(): bool
    {
        $payslip = $this->route('payslip');

        return $payslip instanceof Payslip
            && $this->user()?->can('publish', $payslip) === true;
    }

    public function rules(): array
    {
        return [];
    }
}
