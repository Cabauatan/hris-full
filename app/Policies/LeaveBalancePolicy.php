<?php

namespace App\Policies;

use App\Models\LeaveBalance;
use App\Models\User;

class LeaveBalancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('leave.view');
    }

    public function view(
        User $user,
        LeaveBalance $leaveBalance
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->hasPermission('leave.view')) {
            return false;
        }

        if ($user->hasPermission('employees.view')) {
            return true;
        }

        $viewerEmployeeId = $user->employee()->value('id');

        if ($viewerEmployeeId === null) {
            return false;
        }

        if ($leaveBalance->employee_id === $viewerEmployeeId) {
            return true;
        }

        if (! $user->hasPermission('employees.view-team')) {
            return false;
        }

        return $leaveBalance->employee()
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
        LeaveBalance $leaveBalance
    ): bool {
        return false;
    }

    public function adjust(User $user): bool
    {
        return $user->hasPermission('leave.manage-credits');
    }

    public function delete(
        User $user,
        LeaveBalance $leaveBalance
    ): bool {
        return false;
    }

    public function restore(
        User $user,
        LeaveBalance $leaveBalance
    ): bool {
        return false;
    }

    public function forceDelete(
        User $user,
        LeaveBalance $leaveBalance
    ): bool {
        return false;
    }
}
