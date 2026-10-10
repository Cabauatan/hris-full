<?php

namespace App\Http\Resources;

class EmployeeResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'user_id',
        'position_id',
        'supervisor_employee_id',
        'employee_number',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'birth_date',
        'gender',
        'civil_status',
        'personal_email',
        'company_email',
        'mobile_number',
        'address_line_1',
        'address_line_2',
        'city',
        'province',
        'postal_code',
        'country',
        'hire_date',
        'regularization_date',
        'separation_date',
        'employment_type',
        'employment_status',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected const DATES = ['hire_date', 'regularization_date', 'separation_date', 'birth_date'];
}
