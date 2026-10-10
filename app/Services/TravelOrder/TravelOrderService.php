<?php

namespace App\Services\TravelOrder;

use App\Models\Employee;
use App\Models\TravelOrderRequest;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TravelOrderService
{
    public function __construct(private readonly NotificationService $notifications) {}

    private function ensurePending(
        TravelOrderRequest $request
    ): void {
        if ($request->status !== 'pending') {
            throw new RuntimeException(
                'Travel order request must be pending. '
                ."Current status: {$request->status}."
            );
        }
    }

    public function submit(
        Employee $employee,
        User $createdBy,
        string $startAt,
        string $endAt,
        string $destination,
        string $purpose,
        ?string $documentPath = null
    ): TravelOrderRequest {
        return DB::transaction(function () use ($employee, $createdBy, $startAt, $endAt, $destination, $purpose, $documentPath): TravelOrderRequest {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $start = CarbonImmutable::parse($startAt);
            $end = CarbonImmutable::parse($endAt);

            if (! $end->gt($start)) {
                throw new RuntimeException('The end time must be after the start time.');
            }

            $purpose = trim($purpose);

            if ($purpose === '') {
                throw new RuntimeException('The purpose must not be empty.');
            }

            $destination = trim($destination);

            if ($destination === '') {
                throw new RuntimeException('The destination must not be empty.');
            }

            $request = TravelOrderRequest::query()->create([
                'employee_id' => $employee->id,
                'start_at' => $start,
                'end_at' => $end,
                'destination' => $destination,
                'purpose' => $purpose,
                'document_path' => $documentPath,
                'status' => 'pending',
                'created_by_user_id' => $createdBy->id,
            ]);

            $this->notifications->queue($request, 'pending');

            return $request->fresh(['employee', 'creator', 'approvalActions']);
        });
    }

    public function approve(
        TravelOrderRequest $request,
        User $reviewer,
        ?string $remarks = null
    ): TravelOrderRequest {
        return DB::transaction(function () use ($request, $reviewer, $remarks) {
            $request = TravelOrderRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->id);

            $this->ensurePending($request);

            if ($request->end_at->lessThanOrEqualTo($request->start_at)) {
                throw new RuntimeException(
                    'Travel order end time must be later than start time.'
                );
            }

            $request->update([
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
        TravelOrderRequest $request,
        User $reviewer,
        ?string $remarks = null
    ): TravelOrderRequest {
        return DB::transaction(function () use ($request, $reviewer, $remarks) {
            $request = TravelOrderRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->id);

            $this->ensurePending($request);

            $request->update([
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
        TravelOrderRequest $request,
        User $actor,
        ?string $reason = null
    ): TravelOrderRequest {
        return DB::transaction(function () use ($request, $actor, $reason) {
            $request = TravelOrderRequest::query()
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
