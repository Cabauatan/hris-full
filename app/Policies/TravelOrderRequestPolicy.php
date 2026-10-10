<?php

namespace App\Policies;

use App\Models\TravelOrderRequest;
use App\Models\User;
use App\Services\Approval\ApprovalAuthorityResolver;

class TravelOrderRequestPolicy
{
    public function __construct(
        private readonly ApprovalAuthorityResolver $approvalAuthorityResolver,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('travel-orders.view');
    }

    public function view(
        User $user,
        TravelOrderRequest $travelOrderRequest
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->hasPermission('travel-orders.view')) {
            return false;
        }

        if ($user->hasPermission('employees.view')) {
            return true;
        }

        if ($this->canReview($user, $travelOrderRequest)) {
            return true;
        }

        $viewerEmployeeId = $user->employee()->value('id');

        if ($viewerEmployeeId === null) {
            return false;
        }

        if (
            $travelOrderRequest->employee_id
            === $viewerEmployeeId
        ) {
            return true;
        }

        if (! $user->hasPermission('employees.view-team')) {
            return false;
        }

        return $travelOrderRequest->employee()
            ->where(
                'supervisor_employee_id',
                $viewerEmployeeId
            )
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(
            'travel-orders.create'
        );
    }

    public function update(
        User $user,
        TravelOrderRequest $travelOrderRequest
    ): bool {
        return false;
    }

    public function approve(
        User $user,
        TravelOrderRequest $travelOrderRequest
    ): bool {
        return $this->canReview(
            $user,
            $travelOrderRequest
        );
    }

    public function reject(
        User $user,
        TravelOrderRequest $travelOrderRequest
    ): bool {
        return $this->canReview(
            $user,
            $travelOrderRequest
        );
    }

    public function cancel(
        User $user,
        TravelOrderRequest $travelOrderRequest
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->hasPermission('travel-orders.create')) {
            return false;
        }

        if (
            $travelOrderRequest->created_by_user_id
            === $user->id
        ) {
            return true;
        }

        $viewerEmployeeId = $user->employee()->value('id');

        return $viewerEmployeeId !== null
            && $travelOrderRequest->employee_id
                === $viewerEmployeeId;
    }

    public function delete(
        User $user,
        TravelOrderRequest $travelOrderRequest
    ): bool {
        return false;
    }

    public function restore(
        User $user,
        TravelOrderRequest $travelOrderRequest
    ): bool {
        return false;
    }

    public function forceDelete(
        User $user,
        TravelOrderRequest $travelOrderRequest
    ): bool {
        return false;
    }

    private function canReview(
        User $user,
        TravelOrderRequest $travelOrderRequest
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->hasPermission('travel-orders.approve')) {
            return false;
        }

        $employee = $travelOrderRequest->employee;

        if (! $employee) {
            return false;
        }

        $approver = $this->approvalAuthorityResolver->resolve(
            $employee,
            ApprovalAuthorityResolver::TYPE_TRAVEL_ORDER
        );

        return $approver !== null
            && $approver->is($user);
    }
}
