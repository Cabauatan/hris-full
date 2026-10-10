<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'uuid',
        'name',
        'email',
        'password',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'active_session_token',
        'active_session_last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'active_session_last_activity_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->withTimestamps();
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)
            ->withTimestamps();
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function hasPermission(string $permissionCode): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return Permission::query()
            ->where('permissions.code', $permissionCode)
            ->where('permissions.is_active', true)
            ->where(function (Builder $permissions): void {
                $permissions->whereHas('users', fn (Builder $users): Builder => $users->whereKey($this->getKey()))
                    ->orWhereHas('roles', fn (Builder $roles): Builder => $roles
                        ->where('roles.is_active', true)
                        ->whereHas('users', fn (Builder $users): Builder => $users->whereKey($this->getKey())));
            })->exists();
    }
}
