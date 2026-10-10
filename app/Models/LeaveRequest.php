<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class LeaveRequest extends Model
{
    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'start_date',
        'end_date',
        'day_portion',
        'requested_units',
        'approved_units',
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
            'start_date' => 'date',
            'end_date' => 'date',
            'requested_units' => 'decimal:2',
            'approved_units' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
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

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(LeaveCreditTransaction::class);
    }

    public function approvalActions(): MorphMany
    {
        return $this->morphMany(
            ApprovalAction::class,
            'approvable'
        );
    }
}
