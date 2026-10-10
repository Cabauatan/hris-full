<?php

namespace App\Policies;

use App\Models\Shift;
use App\Models\User;

class ShiftPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('schedules.view');
    }

    public function view(User $user, Shift $shift): bool
    {
        return $user->hasPermission('schedules.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('schedules.manage');
    }

    public function update(User $user, Shift $shift): bool
    {
        return $user->hasPermission('schedules.manage');
    }

    public function delete(User $user, Shift $shift): bool
    {
        return false;
    }

    public function restore(User $user, Shift $shift): bool
    {
        return false;
    }

    public function forceDelete(User $user, Shift $shift): bool
    {
        return false;
    }
}
