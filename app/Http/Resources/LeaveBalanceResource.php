<?php

namespace App\Http\Resources;

class LeaveBalanceResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'employee_id',
        'leave_type_id',
        'year',
        'balance',
        'created_at',
        'updated_at',
    ];
}
