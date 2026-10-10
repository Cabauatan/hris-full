<?php

namespace App\Http\Resources;

class DailyAttendanceResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'employee_id',
        'attendance_date',
        'work_schedule_id',
        'shift_id',
        'scheduled_in',
        'scheduled_out',
        'actual_in',
        'actual_out',
        'worked_minutes',
        'late_minutes',
        'undertime_minutes',
        'overtime_minutes',
        'status',
        'is_rest_day',
        'is_holiday',
        'remarks',
        'calculated_at',
        'created_at',
        'updated_at',
    ];

    protected const DATES = ['attendance_date'];
}
