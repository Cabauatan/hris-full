<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class PayrollEntryResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'payroll_run_id',
        'employee_id',
        'basic_salary',
        'pay_frequency',
        'currency',
        'gross_pay',
        'total_deductions',
        'net_pay',
        'status',
        'calculated_at',
        'remarks',
        'created_at',
        'updated_at',
    ];

    public function toArray(Request $request): array
    {
        return parent::toArray($request) + ['items' => PayrollEntryItemResource::collection($this->whenLoaded('items'))];
    }
}
