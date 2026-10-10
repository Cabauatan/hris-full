<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalDelegation extends Model
{
    protected $fillable = [
        'delegator_user_id',
        'delegate_user_id',
        'starts_at',
        'ends_at',
        'reason',
        'is_active',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'delegator_user_id'
        );
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'delegate_user_id'
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
