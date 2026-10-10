<?php

namespace App\Http\Resources;

class PayrollPeriodResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'code',
        'period_start',
        'period_end',
        'pay_date',
        'pay_frequency',
        'status',
        'created_at',
        'updated_at',
    ];

    protected const DATES = ['pay_date', 'period_start', 'period_end'];
}
