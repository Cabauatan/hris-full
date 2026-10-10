<?php

namespace App\Policies;

use App\Models\ApprovalDelegation;
use App\Models\User;

class ApprovalDelegationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(
            'approval-delegations.view'
        );
    }

    public function view(
        User $user,
        ApprovalDelegation $approvalDelegation
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if (
            ! $user->hasPermission(
                'approval-delegations.view'
            )
        ) {
            return false;
        }

        if ($user->hasPermission('employees.view')) {
            return true;
        }

        return $approvalDelegation->delegator_user_id === $user->id
            || $approvalDelegation->delegate_user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(
            'approval-delegations.manage'
        );
    }

    public function update(
        User $user,
        ApprovalDelegation $approvalDelegation
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if (
            ! $user->hasPermission(
                'approval-delegations.manage'
            )
        ) {
            return false;
        }

        if ($user->hasPermission('employees.view')) {
            return true;
        }

        return $approvalDelegation->delegator_user_id === $user->id;
    }

    public function delete(
        User $user,
        ApprovalDelegation $approvalDelegation
    ): bool {
        return false;
    }

    public function restore(
        User $user,
        ApprovalDelegation $approvalDelegation
    ): bool {
        return false;
    }

    public function forceDelete(
        User $user,
        ApprovalDelegation $approvalDelegation
    ): bool {
        return false;
    }
}
