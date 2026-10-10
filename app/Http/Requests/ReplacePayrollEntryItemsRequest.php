<?php

namespace App\Http\Requests;

use App\Models\PayrollEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReplacePayrollEntryItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $payrollEntry = $this->route('payrollEntry');

        return $payrollEntry instanceof PayrollEntry
            && $this->user()?->can('process', PayrollEntry::class) === true;
    }

    public function rules(): array
    {
        return [
            'items' => ['present', 'array', 'list'],
            'items.*' => ['array:code,name,type,quantity,rate,amount,remarks'],
            'items.*.code' => ['required', 'string', 'max:100'],
            'items.*.name' => ['required', 'string', 'max:150'],
            'items.*.type' => ['required', Rule::in(['earning', 'deduction'])],
            'items.*.quantity' => ['nullable', 'numeric', 'regex:/^-?\d+(?:\.\d{1,4})?$/', 'between:-99999999.9999,99999999.9999'],
            'items.*.rate' => ['nullable', 'numeric', 'regex:/^-?\d+(?:\.\d{1,4})?$/', 'between:-99999999999.9999,99999999999.9999'],
            'items.*.amount' => ['required', 'numeric', 'regex:/^\d+(?:\.\d{1,2})?$/', 'between:0,9999999999999.99'],
            'items.*.remarks' => ['nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // Sum integer centavos so precision and overflow checks do not depend on floats.
            $limit = 999999999999999;
            $totals = ['earning' => 0, 'deduction' => 0];

            foreach ($this->input('items') as $item) {
                [$whole, $fraction] = array_pad(explode('.', (string) $item['amount'], 2), 2, '');
                $centavos = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
                $type = $item['type'];

                if ($centavos > $limit - $totals[$type]) {
                    $validator->errors()->add('items', 'The total earnings or deductions exceed the supported amount.');

                    return;
                }

                $totals[$type] += $centavos;
            }
        }];
    }
}
