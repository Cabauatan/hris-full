<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    protected $fillable = [
        'code',
        'name',
        'start_time',
        'end_time',
        'break_minutes',
        'late_grace_minutes',
        'undertime_grace_minutes',
        'is_overnight',
        'is_active',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'break_minutes' => 'integer',
            'late_grace_minutes' => 'integer',
            'undertime_grace_minutes' => 'integer',
            'is_overnight' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function workScheduleDays(): HasMany
    {
        return $this->hasMany(WorkScheduleDay::class);
    }
}
