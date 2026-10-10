<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CtoCreditTransaction extends Model
{
    protected $fillable = [
        'employee_id',
        'overtime_request_id',
        'cto_request_id',
        'minutes',
        'transaction_type',
        'effective_date',
        'expires_at',
        'remarks',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'minutes' => 'integer',
            'effective_date' => 'date',
            'expires_at' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function overtimeRequest(): BelongsTo
    {
        return $this->belongsTo(OvertimeRequest::class);
    }

    public function ctoRequest(): BelongsTo
    {
        return $this->belongsTo(CtoRequest::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by_user_id'
        );
    }
}
