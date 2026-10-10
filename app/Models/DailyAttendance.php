<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyAttendance extends Model
{
    protected $fillable = [
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
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'scheduled_in' => 'datetime',
            'scheduled_out' => 'datetime',
            'actual_in' => 'datetime',
            'actual_out' => 'datetime',
            'worked_minutes' => 'integer',
            'late_minutes' => 'integer',
            'undertime_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'is_rest_day' => 'boolean',
            'is_holiday' => 'boolean',
            'calculated_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function correctionRequests(): HasMany
    {
        return $this->hasMany(AttendanceCorrectionRequest::class);
    }
}
