<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeLog extends Model
{
    protected $fillable = [
        'employee_id',
        'logged_at',
        'log_type',
        'source',
        'device_code',
        'external_reference',
        'reason',
        'remarks',
        'created_by_user_id',
        'is_valid',
    ];

    protected function casts(): array
    {
        return [
            'logged_at' => 'datetime',
            'is_valid' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by_user_id'
        );
    }
}
