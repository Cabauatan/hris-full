<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkScheduleDay;

class WorkScheduleDayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('schedules.view');
    }

    public function view(User $user, WorkScheduleDay $workScheduleDay): bool
    {
        return $user->hasPermission('schedules.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('schedules.manage');
    }

    public function update(User $user, WorkScheduleDay $workScheduleDay): bool
    {
        return $user->hasPermission('schedules.manage');
    }

    public function delete(User $user, WorkScheduleDay $workScheduleDay): bool
    {
        return false;
    }

    public function restore(User $user, WorkScheduleDay $workScheduleDay): bool
    {
        return false;
    }

    public function forceDelete(User $user, WorkScheduleDay $workScheduleDay): bool
    {
        return false;
    }
}
