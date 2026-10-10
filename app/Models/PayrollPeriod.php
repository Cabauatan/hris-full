<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollPeriod extends Model
{
    protected $fillable = [
        'code',
        'period_start',
        'period_end',
        'pay_date',
        'pay_frequency',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'pay_date' => 'date',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(PayrollRun::class);
    }
}
