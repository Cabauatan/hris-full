<?php

namespace App\Policies;

use App\Models\CtoBalance;
use App\Models\User;

class CtoBalancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('cto.view');
    }

    public function view(
        User $user,
        CtoBalance $ctoBalance
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->hasPermission('cto.view')) {
            return false;
        }

        if ($user->hasPermission('employees.view')) {
            return true;
        }

        $viewerEmployeeId = $user->employee()->value('id');

        if ($viewerEmployeeId === null) {
            return false;
        }

        if ($ctoBalance->employee_id === $viewerEmployeeId) {
            return true;
        }

        if (! $user->hasPermission('employees.view-team')) {
            return false;
        }

        return $ctoBalance->employee()
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
        CtoBalance $ctoBalance
    ): bool {
        return false;
    }

    public function adjust(User $user): bool
    {
        return $user->hasPermission('cto.manage-credits');
    }

    public function delete(
        User $user,
        CtoBalance $ctoBalance
    ): bool {
        return false;
    }

    public function restore(
        User $user,
        CtoBalance $ctoBalance
    ): bool {
        return false;
    }

    public function forceDelete(
        User $user,
        CtoBalance $ctoBalance
    ): bool {
        return false;
    }
}
