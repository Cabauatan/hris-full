<?php

namespace App\Policies;

use App\Models\TimeLog;
use App\Models\User;

class TimeLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('attendance.view');
    }

    public function view(User $user, TimeLog $timeLog): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->hasPermission('attendance.view')) {
            return false;
        }

        if ($user->hasPermission('employees.view')) {
            return true;
        }

        $viewerEmployeeId = $user->employee()->value('id');

        if ($viewerEmployeeId === null) {
            return false;
        }

        if ($timeLog->employee_id === $viewerEmployeeId) {
            return true;
        }

        if (! $user->hasPermission('employees.view-team')) {
            return false;
        }

        return $timeLog->employee()
            ->where(
                'supervisor_employee_id',
                $viewerEmployeeId
            )
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('attendance.manage');
    }

    public function update(User $user, TimeLog $timeLog): bool
    {
        return $user->hasPermission('attendance.manage');
    }

    public function delete(User $user, TimeLog $timeLog): bool
    {
        return false;
    }

    public function restore(User $user, TimeLog $timeLog): bool
    {
        return false;
    }

    public function forceDelete(User $user, TimeLog $timeLog): bool
    {
        return false;
    }
}
