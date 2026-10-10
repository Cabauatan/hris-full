<?php

namespace App\Http\Resources;

class WorkScheduleResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'code',
        'name',
        'description',
        'is_active',
        'created_at',
        'updated_at',
    ];
}
