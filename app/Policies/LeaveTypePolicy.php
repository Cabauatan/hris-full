<?php

namespace App\Policies;

use App\Models\LeaveType;
use App\Models\User;

class LeaveTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('leave.view');
    }

    public function view(
        User $user,
        LeaveType $leaveType
    ): bool {
        return $user->hasPermission('leave.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('leave.manage-types');
    }

    public function update(
        User $user,
        LeaveType $leaveType
    ): bool {
        return $user->hasPermission('leave.manage-types');
    }

    public function delete(
        User $user,
        LeaveType $leaveType
    ): bool {
        return false;
    }

    public function restore(
        User $user,
        LeaveType $leaveType
    ): bool {
        return false;
    }

    public function forceDelete(
        User $user,
        LeaveType $leaveType
    ): bool {
        return false;
    }
}
