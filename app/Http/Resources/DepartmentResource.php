<?php

namespace App\Http\Resources;

class DepartmentResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'division_id',
        'code',
        'name',
        'description',
        'is_active',
        'created_at',
        'updated_at',
    ];
}
