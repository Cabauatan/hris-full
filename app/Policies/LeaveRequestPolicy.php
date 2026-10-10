<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Approval\ApprovalAuthorityResolver;

class LeaveRequestPolicy
{
    public function __construct(
        private readonly ApprovalAuthorityResolver $approvalAuthorityResolver,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('leave.view');
    }

    public function view(
        User $user,
        LeaveRequest $leaveRequest
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

        if ($this->canReview($user, $leaveRequest)) {
            return true;
        }

        $viewerEmployeeId = $user->employee()->value('id');

        if ($viewerEmployeeId === null) {
            return false;
        }

        if ($leaveRequest->employee_id === $viewerEmployeeId) {
            return true;
        }

        if (! $user->hasPermission('employees.view-team')) {
            return false;
        }

        return $leaveRequest->employee()
            ->where(
                'supervisor_employee_id',
                $viewerEmployeeId
            )
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('leave.create');
    }

    public function update(
        User $user,
        LeaveRequest $leaveRequest
    ): bool {
        return false;
    }

    public function approve(
        User $user,
        LeaveRequest $leaveRequest
    ): bool {
        return $this->canReview(
            $user,
            $leaveRequest
        );
    }

    public function reject(
        User $user,
        LeaveRequest $leaveRequest
    ): bool {
        return $this->canReview(
            $user,
            $leaveRequest
        );
    }

    public function cancel(
        User $user,
        LeaveRequest $leaveRequest
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->hasPermission('leave.create')) {
            return false;
        }

        if ($leaveRequest->created_by_user_id === $user->id) {
            return true;
        }

        $viewerEmployeeId = $user->employee()->value('id');

        return $viewerEmployeeId !== null
            && $leaveRequest->employee_id === $viewerEmployeeId;
    }

    public function delete(
        User $user,
        LeaveRequest $leaveRequest
    ): bool {
        return false;
    }

    public function restore(
        User $user,
        LeaveRequest $leaveRequest
    ): bool {
        return false;
    }

    public function forceDelete(
        User $user,
        LeaveRequest $leaveRequest
    ): bool {
        return false;
    }

    private function canReview(
        User $user,
        LeaveRequest $leaveRequest
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->hasPermission('leave.approve')) {
            return false;
        }

        $employee = $leaveRequest->employee;

        if (! $employee) {
            return false;
        }

        $approver = $this->approvalAuthorityResolver->resolve(
            $employee,
            ApprovalAuthorityResolver::TYPE_LEAVE
        );

        return $approver !== null
            && $approver->is($user);
    }
}
