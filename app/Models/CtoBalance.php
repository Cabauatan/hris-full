<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CtoBalance extends Model
{
    protected $fillable = [
        'employee_id',
        'balance_minutes',
    ];

    protected function casts(): array
    {
        return [
            'balance_minutes' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
