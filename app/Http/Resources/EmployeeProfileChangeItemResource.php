<?php

namespace App\Http\Resources;

class EmployeeProfileChangeItemResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'employee_profile_change_request_id',
        'field_name',
        'old_value',
        'new_value',
        'created_at',
        'updated_at',
    ];
}
