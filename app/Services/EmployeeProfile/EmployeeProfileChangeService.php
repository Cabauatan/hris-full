<?php

namespace App\Services\EmployeeProfile;

use App\Models\Employee;
use App\Models\EmployeeProfileChangeRequest;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EmployeeProfileChangeService
{
    public function __construct(private readonly NotificationService $notifications) {}

    private const EDITABLE_FIELDS = [
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'birth_date',
        'gender',
        'civil_status',
        'personal_email',
        'mobile_number',
        'address_line_1',
        'address_line_2',
        'city',
        'province',
        'postal_code',
        'country',
    ];

    private function ensureStatus(
        EmployeeProfileChangeRequest $request,
        string $expectedStatus
    ): void {
        if ($request->status !== $expectedStatus) {
            throw new RuntimeException(
                "Profile change request must be {$expectedStatus}. "
                ."Current status: {$request->status}."
            );
        }
    }

    private function ensureEditableField(string $field): void
    {
        if (! in_array($field, self::EDITABLE_FIELDS, true)) {
            throw new RuntimeException(
                "Profile field is not editable: {$field}."
            );
        }
    }

    private function normalizeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            throw new RuntimeException(
                'Profile change values must be scalar or null.'
            );
        }

        return (string) $value;
    }

    public function submit(
        Employee $employee,
        User $createdBy,
        array $changes,
        ?string $reason = null,
        ?string $documentPath = null
    ): EmployeeProfileChangeRequest {
        return DB::transaction(function () use (
            $employee,
            $createdBy,
            $changes,
            $reason,
            $documentPath
        ) {
            $employee = Employee::query()
                ->lockForUpdate()
                ->findOrFail($employee->id);

            if ($changes === []) {
                throw new RuntimeException(
                    'At least one profile change is required.'
                );
            }

            $items = [];

            foreach ($changes as $field => $newValue) {
                if (! is_string($field)) {
                    throw new RuntimeException(
                        'Profile change fields must be named fields.'
                    );
                }

                $this->ensureEditableField($field);

                $oldValue = $this->normalizeValue(
                    $employee->getRawOriginal($field)
                );

                $newValue = $this->normalizeValue($newValue);

                if ($oldValue === $newValue) {
                    continue;
                }

                $items[] = [
                    'field_name' => $field,
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                ];
            }

            if ($items === []) {
                throw new RuntimeException(
                    'No actual profile changes were provided.'
                );
            }

            $request = EmployeeProfileChangeRequest::create([
                'employee_id' => $employee->id,
                'reason' => $reason,
                'document_path' => $documentPath,
                'status' => 'pending',
                'created_by_user_id' => $createdBy->id,
            ]);

            $request->items()->createMany($items);

            $this->notifications->queue($request, 'pending');

            return $request->fresh([
                'employee',
                'items',
                'creator',
            ]);
        });
    }

    public function approve(
        EmployeeProfileChangeRequest $request,
        User $reviewedBy,
        ?string $remarks = null
    ): EmployeeProfileChangeRequest {
        return DB::transaction(function () use (
            $request,
            $reviewedBy,
            $remarks
        ) {
            $request = EmployeeProfileChangeRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->id);

            $this->ensureStatus($request, 'pending');

            if (! $request->items()->exists()) {
                throw new RuntimeException(
                    'Profile change request has no change items.'
                );
            }

            $request->update([
                'status' => 'approved',
                'reviewed_by_user_id' => $reviewedBy->id,
                'reviewed_at' => now(),
                'review_remarks' => $remarks,
            ]);

            $this->notifications->queue($request, 'approved');

            return $request->fresh([
                'employee',
                'items',
                'reviewer',
                'creator',
            ]);
        });
    }

    public function apply(
        EmployeeProfileChangeRequest $request,
        User $appliedBy
    ): EmployeeProfileChangeRequest {
        return DB::transaction(function () use (
            $request,
            $appliedBy
        ) {
            $request = EmployeeProfileChangeRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->id);

            $this->ensureStatus($request, 'approved');

            $employee = Employee::query()
                ->lockForUpdate()
                ->findOrFail($request->employee_id);

            $items = $request->items()
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw new RuntimeException(
                    'Profile change request has no change items.'
                );
            }

            $updates = [];

            foreach ($items as $item) {
                $this->ensureEditableField($item->field_name);

                $currentValue = $this->normalizeValue(
                    $employee->getRawOriginal($item->field_name)
                );

                $expectedOldValue = $this->normalizeValue(
                    $item->old_value
                );

                if ($currentValue !== $expectedOldValue) {
                    throw new RuntimeException(
                        'Profile field changed after request submission: '
                        ."{$item->field_name}."
                    );
                }

                $updates[$item->field_name] = $item->new_value;
            }

            $employee->update($updates);

            $request->update([
                'status' => 'applied',
                'applied_at' => now(),
                'applied_by_user_id' => $appliedBy->id,
            ]);

            $this->notifications->queue($request, 'applied');

            return $request->fresh([
                'employee',
                'items',
                'reviewer',
                'applier',
                'creator',
            ]);
        });
    }

    public function reject(
        EmployeeProfileChangeRequest $request,
        User $reviewedBy,
        ?string $remarks = null
    ): EmployeeProfileChangeRequest {
        return DB::transaction(function () use (
            $request,
            $reviewedBy,
            $remarks
        ) {
            $request = EmployeeProfileChangeRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->id);

            $this->ensureStatus($request, 'pending');

            $request->update([
                'status' => 'rejected',
                'reviewed_by_user_id' => $reviewedBy->id,
                'reviewed_at' => now(),
                'review_remarks' => $remarks,
            ]);

            $this->notifications->queue($request, 'rejected');

            return $request->fresh([
                'employee',
                'items',
                'reviewer',
                'creator',
            ]);
        });
    }

    public function cancel(
        EmployeeProfileChangeRequest $request,
        ?string $reason = null
    ): EmployeeProfileChangeRequest {
        return DB::transaction(function () use (
            $request,
            $reason
        ) {
            $request = EmployeeProfileChangeRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->id);

            $this->ensureStatus($request, 'pending');

            $request->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            $this->notifications->queue($request, 'cancelled');

            return $request->fresh([
                'employee',
                'items',
                'creator',
            ]);
        });
    }
}
