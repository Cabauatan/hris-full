<?php

namespace App\Http\Requests;

use App\Models\Payslip;
use Illuminate\Foundation\Http\FormRequest;

class MarkPayslipViewedRequest extends FormRequest
{
    public function authorize(): bool
    {
        $payslip = $this->route('payslip');

        return $payslip instanceof Payslip
            && $this->user()?->can('markViewed', $payslip) === true;
    }

    public function rules(): array
    {
        return [];
    }
}
