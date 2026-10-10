<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payslip extends Model
{
    protected $fillable = [
        'payroll_entry_id',
        'employee_id',
        'file_path',
        'generated_at',
        'generated_by_user_id',
        'published_at',
        'published_by_user_id',
        'viewed_at',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'published_at' => 'datetime',
            'viewed_at' => 'datetime',
        ];
    }

    public function payrollEntry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'generated_by_user_id'
        );
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'published_by_user_id'
        );
    }
}
