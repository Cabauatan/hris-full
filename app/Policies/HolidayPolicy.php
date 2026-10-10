<?php

namespace App\Policies;

use App\Models\Holiday;
use App\Models\User;

class HolidayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('holidays.view');
    }

    public function view(User $user, Holiday $holiday): bool
    {
        return $user->hasPermission('holidays.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('holidays.manage');
    }

    public function update(User $user, Holiday $holiday): bool
    {
        return $user->hasPermission('holidays.manage');
    }

    public function delete(User $user, Holiday $holiday): bool
    {
        return false;
    }

    public function restore(User $user, Holiday $holiday): bool
    {
        return false;
    }

    public function forceDelete(User $user, Holiday $holiday): bool
    {
        return false;
    }
}
