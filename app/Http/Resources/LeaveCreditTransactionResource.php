<?php

namespace App\Http\Resources;

class LeaveCreditTransactionResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'employee_id',
        'leave_type_id',
        'leave_request_id',
        'year',
        'units',
        'transaction_type',
        'effective_date',
        'remarks',
        'created_by_user_id',
        'created_at',
        'updated_at',
    ];

    protected const DATES = ['effective_date'];
}
