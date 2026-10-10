<?php

namespace App\Models;

use Database\Factories\ApplicantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Applicant extends Authenticatable
{
    /** @use HasFactory<ApplicantFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'uuid', 'first_name', 'middle_name', 'last_name', 'email',
        'password', 'is_active', 'last_login_at', 'mobile_number',
    ];

    protected $hidden = ['password', 'remember_token', 'active_session_token', 'active_session_last_activity_at'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'active_session_last_activity_at' => 'datetime',
        ];
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }
}
