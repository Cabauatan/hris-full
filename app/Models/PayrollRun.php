<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    protected $fillable = [
        'payroll_period_id',
        'run_number',
        'status',
        'started_at',
        'completed_at',
        'finalized_at',
        'finalized_by_user_id',
        'remarks',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'run_number' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'finalized_by_user_id'
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
