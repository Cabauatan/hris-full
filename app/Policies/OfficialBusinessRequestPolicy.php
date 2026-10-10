<?php

namespace App\Policies;

use App\Models\OfficialBusinessRequest;
use App\Models\User;
use App\Services\Approval\ApprovalAuthorityResolver;

class OfficialBusinessRequestPolicy
{
    public function __construct(
        private readonly ApprovalAuthorityResolver $approvalAuthorityResolver,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('official-business.view');
    }

    public function view(
        User $user,
        OfficialBusinessRequest $officialBusinessRequest
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->hasPermission('official-business.view')) {
            return false;
        }

        if ($user->hasPermission('employees.view')) {
            return true;
        }

        if ($this->canReview($user, $officialBusinessRequest)) {
            return true;
        }

        $viewerEmployeeId = $user->employee()->value('id');

        if ($viewerEmployeeId === null) {
            return false;
        }

        if (
            $officialBusinessRequest->employee_id
            === $viewerEmployeeId
        ) {
            return true;
        }

        if (! $user->hasPermission('employees.view-team')) {
            return false;
        }

        return $officialBusinessRequest->employee()
            ->where(
                'supervisor_employee_id',
                $viewerEmployeeId
            )
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(
            'official-business.create'
        );
    }

    public function update(
        User $user,
        OfficialBusinessRequest $officialBusinessRequest
    ): bool {
        return false;
    }

    public function approve(
        User $user,
        OfficialBusinessRequest $officialBusinessRequest
    ): bool {
        return $this->canReview(
            $user,
            $officialBusinessRequest
        );
    }

    public function reject(
        User $user,
        OfficialBusinessRequest $officialBusinessRequest
    ): bool {
        return $this->canReview(
            $user,
            $officialBusinessRequest
        );
    }

    public function cancel(
        User $user,
        OfficialBusinessRequest $officialBusinessRequest
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->hasPermission('official-business.create')) {
            return false;
        }

        if (
            $officialBusinessRequest->created_by_user_id
            === $user->id
        ) {
            return true;
        }

        $viewerEmployeeId = $user->employee()->value('id');

        return $viewerEmployeeId !== null
            && $officialBusinessRequest->employee_id
                === $viewerEmployeeId;
    }

    public function delete(
        User $user,
        OfficialBusinessRequest $officialBusinessRequest
    ): bool {
        return false;
    }

    public function restore(
        User $user,
        OfficialBusinessRequest $officialBusinessRequest
    ): bool {
        return false;
    }

    public function forceDelete(
        User $user,
        OfficialBusinessRequest $officialBusinessRequest
    ): bool {
        return false;
    }

    private function canReview(
        User $user,
        OfficialBusinessRequest $officialBusinessRequest
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->hasPermission('official-business.approve')) {
            return false;
        }

        $employee = $officialBusinessRequest->employee;

        if (! $employee) {
            return false;
        }

        $approver = $this->approvalAuthorityResolver->resolve(
            $employee,
            ApprovalAuthorityResolver::TYPE_OFFICIAL_BUSINESS
        );

        return $approver !== null
            && $approver->is($user);
    }
}
