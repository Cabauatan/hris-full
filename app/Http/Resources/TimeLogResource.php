<?php

namespace App\Http\Resources;

class TimeLogResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'employee_id',
        'logged_at',
        'log_type',
        'source',
        'device_code',
        'external_reference',
        'reason',
        'remarks',
        'created_by_user_id',
        'is_valid',
        'created_at',
        'updated_at',
    ];
}
