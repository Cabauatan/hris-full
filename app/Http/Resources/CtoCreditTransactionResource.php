<?php

namespace App\Http\Resources;

class CtoCreditTransactionResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'employee_id',
        'overtime_request_id',
        'cto_request_id',
        'minutes',
        'transaction_type',
        'effective_date',
        'expires_at',
        'remarks',
        'created_by_user_id',
        'created_at',
        'updated_at',
    ];

    protected const DATES = ['expires_at', 'effective_date'];
}
