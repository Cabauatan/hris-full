<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeProfileChangeItem extends Model
{
    protected $fillable = [
        'employee_profile_change_request_id',
        'field_name',
        'old_value',
        'new_value',
    ];

    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(
            EmployeeProfileChangeRequest::class,
            'employee_profile_change_request_id'
        );
    }
}
