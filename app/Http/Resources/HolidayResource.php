<?php

namespace App\Http\Resources;

class HolidayResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'name',
        'holiday_date',
        'holiday_type',
        'description',
        'is_working_day',
        'is_paid',
        'is_active',
        'created_by_user_id',
        'created_at',
        'updated_at',
    ];

    protected const DATES = ['holiday_date'];
}
