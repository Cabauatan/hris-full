<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeProfileChangeRequest extends Model
{
    protected $fillable = [
        'employee_id',
        'reason',
        'document_path',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_remarks',
        'applied_at',
        'applied_by_user_id',
        'cancelled_at',
        'cancellation_reason',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'applied_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(EmployeeProfileChangeItem::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'reviewed_by_user_id'
        );
    }

    public function applier(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'applied_by_user_id'
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by_user_id'
        );
    }
}
