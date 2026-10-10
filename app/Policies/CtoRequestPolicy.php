<?php

namespace App\Policies;

use App\Models\CtoRequest;
use App\Models\User;
use App\Services\Approval\ApprovalAuthorityResolver;

class CtoRequestPolicy
{
    public function __construct(
        private readonly ApprovalAuthorityResolver $approvalAuthorityResolver,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('cto.view');
    }

    public function view(
        User $user,
        CtoRequest $ctoRequest
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

        if ($this->canReview($user, $ctoRequest)) {
            return true;
        }

        $viewerEmployeeId = $user->employee()->value('id');

        if ($viewerEmployeeId === null) {
            return false;
        }

        if ($ctoRequest->employee_id === $viewerEmployeeId) {
            return true;
        }

        if (! $user->hasPermission('employees.view-team')) {
            return false;
        }

        return $ctoRequest->employee()
            ->where(
                'supervisor_employee_id',
                $viewerEmployeeId
            )
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('cto.create');
    }

    public function update(
        User $user,
        CtoRequest $ctoRequest
    ): bool {
        return false;
    }

    public function approve(
        User $user,
        CtoRequest $ctoRequest
    ): bool {
        return $this->canReview(
            $user,
            $ctoRequest
        );
    }

    public function reject(
        User $user,
        CtoRequest $ctoRequest
    ): bool {
        return $this->canReview(
            $user,
            $ctoRequest
        );
    }

    public function cancel(
        User $user,
        CtoRequest $ctoRequest
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->hasPermission('cto.create')) {
            return false;
        }

        if ($ctoRequest->created_by_user_id === $user->id) {
            return true;
        }

        $viewerEmployeeId = $user->employee()->value('id');

        return $viewerEmployeeId !== null
            && $ctoRequest->employee_id === $viewerEmployeeId;
    }

    public function delete(
        User $user,
        CtoRequest $ctoRequest
    ): bool {
        return false;
    }

    public function restore(
        User $user,
        CtoRequest $ctoRequest
    ): bool {
        return false;
    }

    public function forceDelete(
        User $user,
        CtoRequest $ctoRequest
    ): bool {
        return false;
    }

    private function canReview(
        User $user,
        CtoRequest $ctoRequest
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->hasPermission('cto.approve')) {
            return false;
        }

        $employee = $ctoRequest->employee;

        if (! $employee) {
            return false;
        }

        $approver = $this->approvalAuthorityResolver->resolve(
            $employee,
            ApprovalAuthorityResolver::TYPE_CTO
        );

        return $approver !== null
            && $approver->is($user);
    }
}
