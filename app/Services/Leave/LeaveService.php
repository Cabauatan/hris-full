<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveCreditTransaction;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LeaveService
{
    public function __construct(private readonly NotificationService $notifications) {}

    private function ensurePending(
        LeaveRequest $request
    ): void {
        if ($request->status !== 'pending') {
            throw new RuntimeException(
                'Leave request must be pending. '
                ."Current status: {$request->status}."
            );
        }
    }

    private function unitsToHundredths(string $units): int
    {
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $units)) {
            throw new RuntimeException(
                'Leave units must be a non-negative decimal with up to two decimal places.'
            );
        }

        [$whole, $decimal] = array_pad(
            explode('.', $units, 2),
            2,
            '0'
        );

        $decimal = str_pad($decimal, 2, '0');

        return ((int) $whole * 100) + (int) $decimal;
    }

    public function submit(
        Employee $employee,
        LeaveType $leaveType,
        User $createdBy,
        string $startDate,
        string $endDate,
        string $dayPortion,
        string $requestedUnits,
        string $reason,
        ?string $documentPath = null
    ): LeaveRequest {
        return DB::transaction(function () use (
            $employee,
            $leaveType,
            $createdBy,
            $startDate,
            $endDate,
            $dayPortion,
            $requestedUnits,
            $reason,
            $documentPath
        ) {
            $employee = Employee::query()
                ->lockForUpdate()
                ->findOrFail($employee->id);

            $leaveType = LeaveType::query()
                ->lockForUpdate()
                ->findOrFail($leaveType->id);

            if (! $leaveType->is_active) {
                throw new RuntimeException(
                    'The selected leave type is not active.'
                );
            }

            $startDate = CarbonImmutable::parse($startDate)->startOfDay();
            $endDate = CarbonImmutable::parse($endDate)->startOfDay();

            if ($endDate->lessThan($startDate)) {
                throw new RuntimeException(
                    'Leave end date cannot be earlier than start date.'
                );
            }

            $dayPortion = trim($dayPortion);

            if ($dayPortion === '') {
                throw new RuntimeException(
                    'Leave day portion must not be empty.'
                );
            }

            if (
                $dayPortion !== 'full_day'
                && ! $leaveType->allows_partial_day
            ) {
                throw new RuntimeException(
                    'The selected leave type does not allow partial-day leave.'
                );
            }

            $requestedHundredths = $this->unitsToHundredths(
                $requestedUnits
            );

            if ($requestedHundredths < 1) {
                throw new RuntimeException(
                    'Requested leave units must be greater than zero.'
                );
            }

            if (
                $leaveType->requires_balance
                && $startDate->year !== $endDate->year
            ) {
                throw new RuntimeException(
                    'Balance-requiring leave cannot span multiple calendar years.'
                );
            }

            if (
                $leaveType->requires_document
                && (
                    $documentPath === null
                    || trim($documentPath) === ''
                )
            ) {
                throw new RuntimeException(
                    'A supporting document is required for this leave type.'
                );
            }

            $reason = trim($reason);

            if ($reason === '') {
                throw new RuntimeException(
                    'Leave reason must not be empty.'
                );
            }

            $request = LeaveRequest::query()->create([
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'day_portion' => $dayPortion,
                'requested_units' => $requestedUnits,
                'approved_units' => null,
                'reason' => $reason,
                'document_path' => $documentPath,
                'status' => 'pending',
                'created_by_user_id' => $createdBy->id,
            ]);

            $this->notifications->queue($request, 'pending');

            return $request->fresh([
                'employee',
                'leaveType',
                'creator',
                'approvalActions',
            ]);
        });
    }

    public function approve(
        LeaveRequest $request,
        User $reviewer,
        string $approvedUnits,
        ?string $remarks = null
    ): LeaveRequest {
        return DB::transaction(function () use (
            $request,
            $reviewer,
            $approvedUnits,
            $remarks
        ) {
            $request = LeaveRequest::query()
                ->with('leaveType')
                ->lockForUpdate()
                ->findOrFail($request->id);

            $this->ensurePending($request);

            if ($request->end_date->lessThan($request->start_date)) {
                throw new RuntimeException(
                    'Leave end date cannot be earlier than start date.'
                );
            }

            $approvedHundredths = $this->unitsToHundredths($approvedUnits);
            $requestedHundredths = $this->unitsToHundredths(
                $request->requested_units
            );

            if ($approvedHundredths < 1) {
                throw new RuntimeException(
                    'Approved leave units must be greater than zero.'
                );
            }

            if ($approvedHundredths > $requestedHundredths) {
                throw new RuntimeException(
                    'Approved leave units cannot exceed requested leave units.'
                );
            }

            if (
                $request->leaveType->requires_balance
                && $request->start_date->year !== $request->end_date->year
            ) {
                throw new RuntimeException(
                    'Balance-requiring leave cannot span multiple calendar years.'
                );
            }

            if ($request->leaveType->requires_balance) {
                $year = $request->start_date->year;

                $balance = LeaveBalance::query()
                    ->where('employee_id', $request->employee_id)
                    ->where('leave_type_id', $request->leave_type_id)
                    ->where('year', $year)
                    ->lockForUpdate()
                    ->first();

                if (! $balance) {
                    throw new RuntimeException(
                        'No leave balance exists for the requested leave type and year.'
                    );
                }

                $balanceHundredths = $this->unitsToHundredths(
                    $balance->balance
                );

                if ($balanceHundredths < $approvedHundredths) {
                    throw new RuntimeException(
                        'Insufficient leave balance.'
                    );
                }

                $newBalanceHundredths =
                    $balanceHundredths - $approvedHundredths;

                $newBalance = sprintf(
                    '%d.%02d',
                    intdiv($newBalanceHundredths, 100),
                    $newBalanceHundredths % 100
                );

                $balance->update([
                    'balance' => $newBalance,
                ]);

                LeaveCreditTransaction::create([
                    'employee_id' => $request->employee_id,
                    'leave_type_id' => $request->leave_type_id,
                    'leave_request_id' => $request->id,
                    'year' => $year,
                    'units' => sprintf(
                        '-%d.%02d',
                        intdiv($approvedHundredths, 100),
                        $approvedHundredths % 100
                    ),
                    'transaction_type' => 'leave_approval',
                    'effective_date' => $request->start_date,
                    'remarks' => $remarks,
                    'created_by_user_id' => $reviewer->id,
                ]);
            }

            $request->update([
                'approved_units' => $approvedUnits,
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
                'leaveType',
                'approvalActions',
                'creditTransactions',
            ]);
        });
    }

    public function reject(
        LeaveRequest $request,
        User $reviewer,
        ?string $remarks = null
    ): LeaveRequest {
        return DB::transaction(function () use ($request, $reviewer, $remarks) {
            $request = LeaveRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->id);

            $this->ensurePending($request);

            $request->update([
                'approved_units' => null,
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
                'leaveType',
                'approvalActions',
            ]);
        });
    }

    public function cancel(
        LeaveRequest $request,
        User $actor,
        ?string $reason = null
    ): LeaveRequest {
        return DB::transaction(function () use ($request, $actor, $reason) {
            $request = LeaveRequest::query()
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
                'leaveType',
                'approvalActions',
            ]);
        });
    }
}
