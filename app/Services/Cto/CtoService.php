<?php

namespace App\Services\Cto;

use App\Models\CtoBalance;
use App\Models\CtoCreditTransaction;
use App\Models\CtoRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CtoService
{
    public function __construct(private readonly NotificationService $notifications) {}

    private function ensurePending(
        CtoRequest $request
    ): void {
        if ($request->status !== 'pending') {
            throw new RuntimeException(
                'CTO request must be pending. '
                ."Current status: {$request->status}."
            );
        }
    }

    public function submit(
        Employee $employee,
        User $createdBy,
        string $startAt,
        string $endAt,
        string $reason
    ): CtoRequest {
        return DB::transaction(function () use (
            $employee,
            $createdBy,
            $startAt,
            $endAt,
            $reason
        ) {
            $employee = Employee::query()
                ->lockForUpdate()
                ->findOrFail($employee->id);

            $startAt = CarbonImmutable::parse($startAt);
            $endAt = CarbonImmutable::parse($endAt);

            if ($endAt->lessThanOrEqualTo($startAt)) {
                throw new RuntimeException(
                    'CTO end time must be later than start time.'
                );
            }

            $requestedMinutes = (int) $startAt->diffInMinutes(
                $endAt
            );

            if ($requestedMinutes < 1) {
                throw new RuntimeException(
                    'Requested CTO minutes must be greater than zero.'
                );
            }

            $reason = trim($reason);

            if ($reason === '') {
                throw new RuntimeException(
                    'CTO reason must not be empty.'
                );
            }

            $request = CtoRequest::query()->create([
                'employee_id' => $employee->id,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'requested_minutes' => $requestedMinutes,
                'approved_minutes' => null,
                'reason' => $reason,
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
        CtoRequest $request,
        User $reviewer,
        int $approvedMinutes,
        ?string $remarks = null
    ): CtoRequest {
        return DB::transaction(function () use (
            $request,
            $reviewer,
            $approvedMinutes,
            $remarks
        ) {
            $request = CtoRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->id);

            $this->ensurePending($request);

            if ($request->end_at->lessThanOrEqualTo($request->start_at)) {
                throw new RuntimeException(
                    'CTO end time must be later than start time.'
                );
            }

            if ($approvedMinutes < 1) {
                throw new RuntimeException(
                    'Approved CTO minutes must be greater than zero.'
                );
            }

            if ($approvedMinutes > $request->requested_minutes) {
                throw new RuntimeException(
                    'Approved CTO minutes cannot exceed requested CTO minutes.'
                );
            }

            $balance = CtoBalance::query()
                ->where('employee_id', $request->employee_id)
                ->lockForUpdate()
                ->first();

            if (! $balance) {
                throw new RuntimeException(
                    'No CTO balance exists for this employee.'
                );
            }

            if ($balance->balance_minutes < $approvedMinutes) {
                throw new RuntimeException(
                    'Insufficient CTO balance.'
                );
            }

            $balance->update([
                'balance_minutes' => $balance->balance_minutes - $approvedMinutes,
            ]);

            CtoCreditTransaction::create([
                'employee_id' => $request->employee_id,
                'overtime_request_id' => null,
                'cto_request_id' => $request->id,
                'minutes' => -$approvedMinutes,
                'transaction_type' => 'cto_usage',
                'effective_date' => $request->start_at->toDateString(),
                'expires_at' => null,
                'remarks' => $remarks,
                'created_by_user_id' => $reviewer->id,
            ]);

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
                'creditTransactions',
            ]);
        });
    }

    public function reject(
        CtoRequest $request,
        User $reviewer,
        ?string $remarks = null
    ): CtoRequest {
        return DB::transaction(function () use ($request, $reviewer, $remarks) {
            $request = CtoRequest::query()
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
        CtoRequest $request,
        User $actor,
        ?string $reason = null
    ): CtoRequest {
        return DB::transaction(function () use ($request, $actor, $reason) {
            $request = CtoRequest::query()
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
