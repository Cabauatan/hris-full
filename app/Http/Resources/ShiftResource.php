<?php

namespace App\Http\Resources;

class ShiftResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'code',
        'name',
        'start_time',
        'end_time',
        'break_minutes',
        'late_grace_minutes',
        'undertime_grace_minutes',
        'is_overnight',
        'is_active',
        'remarks',
        'created_at',
        'updated_at',
    ];
}
