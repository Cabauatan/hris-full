<?php

namespace App\Services\Overtime;

use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OvertimeService
{
    public function __construct(private readonly NotificationService $notifications) {}

    private function ensurePending(
        OvertimeRequest $request
    ): void {
        if ($request->status !== 'pending') {
            throw new RuntimeException(
                'Overtime request must be pending. '
                ."Current status: {$request->status}."
            );
        }
    }

    public function submit(
        Employee $employee,
        User $createdBy,
        string $startAt,
        string $endAt,
        string $reason,
        ?string $documentPath = null
    ): OvertimeRequest {
        return DB::transaction(function () use (
            $employee,
            $createdBy,
            $startAt,
            $endAt,
            $reason,
            $documentPath
        ) {
            $employee = Employee::query()
                ->lockForUpdate()
                ->findOrFail($employee->id);

            $startAt = CarbonImmutable::parse($startAt);
            $endAt = CarbonImmutable::parse($endAt);

            if ($endAt->lessThanOrEqualTo($startAt)) {
                throw new RuntimeException(
                    'Overtime end time must be later than start time.'
                );
            }

            $requestedMinutes = (int) $startAt->diffInMinutes(
                $endAt
            );

            if ($requestedMinutes < 1) {
                throw new RuntimeException(
                    'Requested overtime minutes must be greater than zero.'
                );
            }

            $reason = trim($reason);

            if ($reason === '') {
                throw new RuntimeException(
                    'Overtime reason must not be empty.'
                );
            }

            $request = OvertimeRequest::query()->create([
                'employee_id' => $employee->id,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'requested_minutes' => $requestedMinutes,
                'approved_minutes' => null,
                'reason' => $reason,
                'document_path' => $documentPath,
                'status' => 'pending',
                'created_by_user_id' => $createdBy->id,
            ]);

            $this->notifications->queue($request, 'pending');

            return $request->fresh([
                'employee',
                'creator',
                'approvalActions',
            ]);
        });
    }

    public function approve(
        OvertimeRequest $request,
        User $reviewer,
        int $approvedMinutes,
        ?string $remarks = null
    ): OvertimeRequest {
        return DB::transaction(function () use (
            $request,
            $reviewer,
            $approvedMinutes,
            $remarks
        ) {
            $request = OvertimeRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->id);

            $this->ensurePending($request);

            if ($request->end_at->lessThanOrEqualTo($request->start_at)) {
                throw new RuntimeException(
                    'Overtime end time must be later than start time.'
                );
            }

            if ($approvedMinutes < 1) {
                throw new RuntimeException(
                    'Approved overtime minutes must be greater than zero.'
                );
            }

            if ($approvedMinutes > $request->requested_minutes) {
                throw new RuntimeException(
                    'Approved overtime minutes cannot exceed requested overtime minutes.'
                );
            }

            $request->update([
                'approved_minutes' => $approvedMinutes,
                'status' => 'approved',
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'review_remarks' => $remarks,
            ]);

            $request->approvalActions()->create([
                'acted_by_user_id' => $reviewer->id,
                'action' => 'approved',
                'remarks' => $remarks,
                'acted_at' => now(),
            ]);

            $this->notifications->queue($request, 'approved');

            return $request->fresh([
                'employee',
                'approvalActions',
            ]);
        });
    }

    public function reject(
        OvertimeRequest $request,
        User $reviewer,
        ?string $remarks = null
    ): OvertimeRequest {
        return DB::transaction(function () use ($request, $reviewer, $remarks) {
            $request = OvertimeRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->id);

            $this->ensurePending($request);

            $request->update([
                'approved_minutes' => null,
                'status' => 'rejected',
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'review_remarks' => $remarks,
            ]);

            $request->approvalActions()->create([
                'acted_by_user_id' => $reviewer->id,
                'action' => 'rejected',
                'remarks' => $remarks,
                'acted_at' => now(),
            ]);

            $this->notifications->queue($request, 'rejected');

            return $request->fresh([
                'employee',
                'approvalActions',
            ]);
        });
    }

    public function cancel(
        OvertimeRequest $request,
        User $actor,
        ?string $reason = null
    ): OvertimeRequest {
        return DB::transaction(function () use ($request, $actor, $reason) {
            $request = OvertimeRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->id);

            $this->ensurePending($request);

            $request->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            $request->approvalActions()->create([
                'acted_by_user_id' => $actor->id,
                'action' => 'cancelled',
                'remarks' => $reason,
                'acted_at' => now(),
            ]);

            $this->notifications->queue($request, 'cancelled');

            return $request->fresh([
                'employee',
                'approvalActions',
            ]);
        });
    }
}
