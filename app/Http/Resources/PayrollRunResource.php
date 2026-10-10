<?php

namespace App\Http\Resources;

class PayrollRunResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'payroll_period_id',
        'run_number',
        'status',
        'started_at',
        'completed_at',
        'finalized_at',
        'finalized_by_user_id',
        'remarks',
        'created_by_user_id',
        'created_at',
        'updated_at',
    ];
}
