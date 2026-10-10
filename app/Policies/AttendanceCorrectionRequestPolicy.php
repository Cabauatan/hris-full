<?php

namespace App\Policies;

use App\Models\AttendanceCorrectionRequest;
use App\Models\User;
use App\Services\Approval\ApprovalAuthorityResolver;

class AttendanceCorrectionRequestPolicy
{
    public function __construct(
        private readonly ApprovalAuthorityResolver $approvalAuthorityResolver,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('attendance-corrections.view');
    }

    public function view(
        User $user,
        AttendanceCorrectionRequest $attendanceCorrectionRequest
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->hasPermission('attendance-corrections.view')) {
            return false;
        }

        if ($user->hasPermission('employees.view')) {
            return true;
        }

        if ($this->canReview($user, $attendanceCorrectionRequest)) {
            return true;
        }

        $viewerEmployeeId = $user->employee()->value('id');

        if ($viewerEmployeeId === null) {
            return false;
        }

        if (
            $attendanceCorrectionRequest->employee_id
            === $viewerEmployeeId
        ) {
            return true;
        }

        if (! $user->hasPermission('employees.view-team')) {
            return false;
        }

        return $attendanceCorrectionRequest->employee()
            ->where(
                'supervisor_employee_id',
                $viewerEmployeeId
            )
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(
            'attendance-corrections.create'
        );
    }

    public function update(
        User $user,
        AttendanceCorrectionRequest $attendanceCorrectionRequest
    ): bool {
        return false;
    }

    public function approve(
        User $user,
        AttendanceCorrectionRequest $attendanceCorrectionRequest
    ): bool {
        return $this->canReview(
            $user,
            $attendanceCorrectionRequest
        );
    }

    public function reject(
        User $user,
        AttendanceCorrectionRequest $attendanceCorrectionRequest
    ): bool {
        return $this->canReview(
            $user,
            $attendanceCorrectionRequest
        );
    }

    public function cancel(
        User $user,
        AttendanceCorrectionRequest $attendanceCorrectionRequest
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if (
            ! $user->hasPermission(
                'attendance-corrections.create'
            )
        ) {
            return false;
        }

        if (
            $attendanceCorrectionRequest->created_by_user_id
            === $user->id
        ) {
            return true;
        }

        $viewerEmployeeId = $user->employee()->value('id');

        return $viewerEmployeeId !== null
            && $attendanceCorrectionRequest->employee_id
                === $viewerEmployeeId;
    }

    public function delete(
        User $user,
        AttendanceCorrectionRequest $attendanceCorrectionRequest
    ): bool {
        return false;
    }

    public function restore(
        User $user,
        AttendanceCorrectionRequest $attendanceCorrectionRequest
    ): bool {
        return false;
    }

    public function forceDelete(
        User $user,
        AttendanceCorrectionRequest $attendanceCorrectionRequest
    ): bool {
        return false;
    }

    private function canReview(
        User $user,
        AttendanceCorrectionRequest $attendanceCorrectionRequest
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if (
            ! $user->hasPermission(
                'attendance-corrections.approve'
            )
        ) {
            return false;
        }

        $employee = $attendanceCorrectionRequest->employee;

        if (! $employee) {
            return false;
        }

        $approver = $this->approvalAuthorityResolver->resolve(
            $employee,
            ApprovalAuthorityResolver::TYPE_ATTENDANCE_CORRECTION
        );

        return $approver !== null
            && $approver->is($user);
    }
}
