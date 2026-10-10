<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkSchedule;

class WorkSchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('schedules.view');
    }

    public function view(User $user, WorkSchedule $workSchedule): bool
    {
        return $user->hasPermission('schedules.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('schedules.manage');
    }

    public function update(User $user, WorkSchedule $workSchedule): bool
    {
        return $user->hasPermission('schedules.manage');
    }

    public function delete(User $user, WorkSchedule $workSchedule): bool
    {
        return false;
    }

    public function restore(User $user, WorkSchedule $workSchedule): bool
    {
        return false;
    }

    public function forceDelete(User $user, WorkSchedule $workSchedule): bool
    {
        return false;
    }
}
