<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holiday extends Model
{
    protected $fillable = [
        'name',
        'holiday_date',
        'holiday_type',
        'description',
        'is_working_day',
        'is_paid',
        'is_active',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
            'is_working_day' => 'boolean',
            'is_paid' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by_user_id'
        );
    }
}
