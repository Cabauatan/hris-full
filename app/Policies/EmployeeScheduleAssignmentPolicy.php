<?php

namespace App\Policies;

use App\Models\EmployeeScheduleAssignment;
use App\Models\User;

class EmployeeScheduleAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('schedules.view')
            || $user->hasPermission('schedules.assign');
    }

    public function view(
        User $user,
        EmployeeScheduleAssignment $employeeScheduleAssignment
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if ($user->hasPermission('schedules.assign')) {
            return true;
        }

        if (! $user->hasPermission('schedules.view')) {
            return false;
        }

        $viewerEmployeeId = $user->employee()->value('id');

        if ($viewerEmployeeId === null) {
            return false;
        }

        if ($employeeScheduleAssignment->employee_id === $viewerEmployeeId) {
            return true;
        }

        if (! $user->hasPermission('employees.view-team')) {
            return false;
        }

        return $employeeScheduleAssignment->employee()
            ->where(
                'supervisor_employee_id',
                $viewerEmployeeId
            )
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('schedules.assign');
    }

    public function update(
        User $user,
        EmployeeScheduleAssignment $employeeScheduleAssignment
    ): bool {
        return $user->hasPermission('schedules.assign');
    }

    public function delete(
        User $user,
        EmployeeScheduleAssignment $employeeScheduleAssignment
    ): bool {
        return false;
    }

    public function restore(
        User $user,
        EmployeeScheduleAssignment $employeeScheduleAssignment
    ): bool {
        return false;
    }

    public function forceDelete(
        User $user,
        EmployeeScheduleAssignment $employeeScheduleAssignment
    ): bool {
        return false;
    }
}
