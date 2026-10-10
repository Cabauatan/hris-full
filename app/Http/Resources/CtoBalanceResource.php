<?php

namespace App\Http\Resources;

class CtoBalanceResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'employee_id',
        'balance_minutes',
        'created_at',
        'updated_at',
    ];
}
