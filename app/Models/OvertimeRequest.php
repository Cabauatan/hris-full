<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class OvertimeRequest extends Model
{
    protected $fillable = [
        'employee_id',
        'start_at',
        'end_at',
        'requested_minutes',
        'approved_minutes',
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
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'requested_minutes' => 'integer',
            'approved_minutes' => 'integer',
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
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

    public function ctoCreditTransactions(): HasMany
    {
        return $this->hasMany(CtoCreditTransaction::class);
    }
}
