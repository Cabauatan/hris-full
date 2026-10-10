<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollEntryItem extends Model
{
    protected $fillable = [
        'payroll_entry_id',
        'code',
        'name',
        'type',
        'quantity',
        'rate',
        'amount',
        'source_type',
        'source_id',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'rate' => 'decimal:4',
            'amount' => 'decimal:2',
        ];
    }

    public function payrollEntry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class);
    }
}
