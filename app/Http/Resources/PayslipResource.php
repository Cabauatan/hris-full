<?php

namespace App\Http\Resources;

class PayslipResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'payroll_entry_id',
        'employee_id',
        'generated_at',
        'generated_by_user_id',
        'published_at',
        'published_by_user_id',
        'viewed_at',
        'created_at',
        'updated_at',
    ];
}
