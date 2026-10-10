<?php

namespace App\Policies;

use App\Models\LeaveCreditTransaction;
use App\Models\User;

class LeaveCreditTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('leave.view');
    }

    public function view(
        User $user,
        LeaveCreditTransaction $leaveCreditTransaction
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

        if (
            $leaveCreditTransaction->employee_id
            === $viewerEmployeeId
        ) {
            return true;
        }

        if (! $user->hasPermission('employees.view-team')) {
            return false;
        }

        return $leaveCreditTransaction->employee()
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
        LeaveCreditTransaction $leaveCreditTransaction
    ): bool {
        return false;
    }

    public function delete(
        User $user,
        LeaveCreditTransaction $leaveCreditTransaction
    ): bool {
        return false;
    }

    public function restore(
        User $user,
        LeaveCreditTransaction $leaveCreditTransaction
    ): bool {
        return false;
    }

    public function forceDelete(
        User $user,
        LeaveCreditTransaction $leaveCreditTransaction
    ): bool {
        return false;
    }
}
