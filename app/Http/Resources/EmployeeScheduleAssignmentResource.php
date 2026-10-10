<?php

namespace App\Http\Resources;

class EmployeeScheduleAssignmentResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'employee_id',
        'work_schedule_id',
        'effective_from',
        'effective_to',
        'remarks',
        'created_by_user_id',
        'created_at',
        'updated_at',
    ];

    protected const DATES = ['effective_to', 'effective_from'];
}
