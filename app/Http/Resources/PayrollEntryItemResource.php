<?php

namespace App\Http\Resources;

class PayrollEntryItemResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'payroll_entry_id',
        'code',
        'name',
        'type',
        'quantity',
        'rate',
        'amount',
        'source_type',
        'source_id',
        'remarks',
        'created_at',
        'updated_at',
    ];
}
