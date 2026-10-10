<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class OfficialBusinessRequest extends Model
{
    protected $fillable = [
        'employee_id',
        'start_at',
        'end_at',
        'destination',
        'purpose',
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
}
