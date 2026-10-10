<?php

namespace App\Policies;

use App\Models\DailyAttendance;
use App\Models\User;

class DailyAttendancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('attendance.view');
    }

    public function view(
        User $user,
        DailyAttendance $dailyAttendance
    ): bool {
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

        if ($dailyAttendance->employee_id === $viewerEmployeeId) {
            return true;
        }

        if (! $user->hasPermission('employees.view-team')) {
            return false;
        }

        return $dailyAttendance->employee()
            ->where(
                'supervisor_employee_id',
                $viewerEmployeeId
            )
            ->exists();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(
        User $user,
        DailyAttendance $dailyAttendance
    ): bool {
        return false;
    }

    public function delete(
        User $user,
        DailyAttendance $dailyAttendance
    ): bool {
        return false;
    }

    public function restore(
        User $user,
        DailyAttendance $dailyAttendance
    ): bool {
        return false;
    }

    public function forceDelete(
        User $user,
        DailyAttendance $dailyAttendance
    ): bool {
        return false;
    }
}
