<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class AttendanceCorrectionRequest extends Model
{
    protected $fillable = [
        'employee_id',
        'daily_attendance_id',
        'attendance_date',
        'requested_time_in',
        'requested_time_out',
        'reason',
        'document_path',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_remarks',
        'cancelled_at',
        'cancellation_reason',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'requested_time_in' => 'datetime',
            'requested_time_out' => 'datetime',
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function dailyAttendance(): BelongsTo
    {
        return $this->belongsTo(DailyAttendance::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'reviewed_by_user_id'
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by_user_id'
        );
    }

    public function approvalActions(): MorphMany
    {
        return $this->morphMany(
            ApprovalAction::class,
            'approvable'
        );
    }
}
