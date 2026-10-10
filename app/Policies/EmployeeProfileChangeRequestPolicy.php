<?php

namespace App\Policies;

use App\Models\EmployeeProfileChangeRequest;
use App\Models\User;

class EmployeeProfileChangeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('profile-changes.view');
    }

    public function view(
        User $user,
        EmployeeProfileChangeRequest $request
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->hasPermission('profile-changes.view')) {
            return false;
        }

        if ($user->hasPermission('employees.view')) {
            return true;
        }

        $viewerEmployeeId = $user->employee()->value('id');

        if ($viewerEmployeeId === null) {
            return false;
        }

        return $request->employee_id === $viewerEmployeeId;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('profile-changes.create');
    }

    public function update(
        User $user,
        EmployeeProfileChangeRequest $request
    ): bool {
        return false;
    }

    public function approve(
        User $user,
        EmployeeProfileChangeRequest $request
    ): bool {
        return $user->hasPermission('profile-changes.approve');
    }

    public function reject(
        User $user,
        EmployeeProfileChangeRequest $request
    ): bool {
        return $user->hasPermission('profile-changes.approve');
    }

    public function apply(
        User $user,
        EmployeeProfileChangeRequest $request
    ): bool {
        return $user->hasPermission('profile-changes.approve');
    }

    public function cancel(
        User $user,
        EmployeeProfileChangeRequest $request
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->hasPermission('profile-changes.create')) {
            return false;
        }

        if ($request->created_by_user_id === $user->id) {
            return true;
        }

        $viewerEmployeeId = $user->employee()->value('id');

        if ($viewerEmployeeId === null) {
            return false;
        }

        return $request->employee_id === $viewerEmployeeId;
    }

    public function delete(
        User $user,
        EmployeeProfileChangeRequest $request
    ): bool {
        return false;
    }

    public function restore(
        User $user,
        EmployeeProfileChangeRequest $request
    ): bool {
        return false;
    }

    public function forceDelete(
        User $user,
        EmployeeProfileChangeRequest $request
    ): bool {
        return false;
    }
}
