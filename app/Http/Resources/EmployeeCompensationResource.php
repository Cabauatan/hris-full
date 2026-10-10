<?php

namespace App\Http\Resources;

class EmployeeCompensationResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'employee_id',
        'basic_salary',
        'pay_frequency',
        'currency',
        'effective_from',
        'effective_to',
        'remarks',
        'created_by_user_id',
        'created_at',
        'updated_at',
    ];

    protected const DATES = ['effective_to', 'effective_from'];
}
