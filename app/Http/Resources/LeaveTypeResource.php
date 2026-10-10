<?php

namespace App\Http\Resources;

class LeaveTypeResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'code',
        'name',
        'description',
        'is_paid',
        'requires_balance',
        'allows_partial_day',
        'requires_document',
        'is_active',
        'created_at',
        'updated_at',
    ];
}
