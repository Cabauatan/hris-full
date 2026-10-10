<?php

namespace App\Http\Resources;

class PositionResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'department_id',
        'code',
        'name',
        'description',
        'is_active',
        'created_at',
        'updated_at',
    ];
}
