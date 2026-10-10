<?php

namespace App\Policies;

use App\Models\CtoCreditTransaction;
use App\Models\User;

class CtoCreditTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('cto.view');
    }

    public function view(
        User $user,
        CtoCreditTransaction $ctoCreditTransaction
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

        if (
            $ctoCreditTransaction->employee_id
            === $viewerEmployeeId
        ) {
            return true;
        }

        if (! $user->hasPermission('employees.view-team')) {
            return false;
        }

        return $ctoCreditTransaction->employee()
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
        CtoCreditTransaction $ctoCreditTransaction
    ): bool {
        return false;
    }

    public function delete(
        User $user,
        CtoCreditTransaction $ctoCreditTransaction
    ): bool {
        return false;
    }

    public function restore(
        User $user,
        CtoCreditTransaction $ctoCreditTransaction
    ): bool {
        return false;
    }

    public function forceDelete(
        User $user,
        CtoCreditTransaction $ctoCreditTransaction
    ): bool {
        return false;
    }
}
