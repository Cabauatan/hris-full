<?php

namespace App\Policies;

use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\Approval\ApprovalAuthorityResolver;

class OvertimeRequestPolicy
{
    public function __construct(
        private readonly ApprovalAuthorityResolver $approvalAuthorityResolver,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('overtime.view');
    }

    public function view(
        User $user,
        OvertimeRequest $overtimeRequest
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->hasPermission('overtime.view')) {
            return false;
        }

        if ($user->hasPermission('employees.view')) {
            return true;
        }

        if ($this->canReview($user, $overtimeRequest)) {
            return true;
        }

        $viewerEmployeeId = $user->employee()->value('id');

        if ($viewerEmployeeId === null) {
            return false;
        }

        if ($overtimeRequest->employee_id === $viewerEmployeeId) {
            return true;
        }

        if (! $user->hasPermission('employees.view-team')) {
            return false;
        }

        return $overtimeRequest->employee()
            ->where(
                'supervisor_employee_id',
                $viewerEmployeeId
            )
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('overtime.create');
    }

    public function update(
        User $user,
        OvertimeRequest $overtimeRequest
    ): bool {
        return false;
    }

    public function approve(
        User $user,
        OvertimeRequest $overtimeRequest
    ): bool {
        return $this->canReview(
            $user,
            $overtimeRequest
        );
    }

    public function reject(
        User $user,
        OvertimeRequest $overtimeRequest
    ): bool {
        return $this->canReview(
            $user,
            $overtimeRequest
        );
    }

    public function cancel(
        User $user,
        OvertimeRequest $overtimeRequest
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->hasPermission('overtime.create')) {
            return false;
        }

        if ($overtimeRequest->created_by_user_id === $user->id) {
            return true;
        }

        $viewerEmployeeId = $user->employee()->value('id');

        return $viewerEmployeeId !== null
            && $overtimeRequest->employee_id === $viewerEmployeeId;
    }

    public function delete(
        User $user,
        OvertimeRequest $overtimeRequest
    ): bool {
        return false;
    }

    public function restore(
        User $user,
        OvertimeRequest $overtimeRequest
    ): bool {
        return false;
    }

    public function forceDelete(
        User $user,
        OvertimeRequest $overtimeRequest
    ): bool {
        return false;
    }

    private function canReview(
        User $user,
        OvertimeRequest $overtimeRequest
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->hasPermission('overtime.approve')) {
            return false;
        }

        $employee = $overtimeRequest->employee;

        if (! $employee) {
            return false;
        }

        $approver = $this->approvalAuthorityResolver->resolve(
            $employee,
            ApprovalAuthorityResolver::TYPE_OVERTIME
        );

        return $approver !== null
            && $approver->is($user);
    }
}
