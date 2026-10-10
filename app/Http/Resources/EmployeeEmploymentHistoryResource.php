<?php

namespace App\Http\Resources;

class EmployeeEmploymentHistoryResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'employee_id',
        'position_id',
        'supervisor_employee_id',
        'employment_type',
        'employment_status',
        'effective_from',
        'effective_to',
        'change_type',
        'reason',
        'remarks',
        'created_by_user_id',
        'created_at',
        'updated_at',
    ];

    protected const DATES = ['effective_to', 'effective_from'];
}
