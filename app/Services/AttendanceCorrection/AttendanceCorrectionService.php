<?php

namespace App\Services\AttendanceCorrection;

use App\Models\AttendanceCorrectionRequest;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\AttendanceCalculationService;
use App\Services\Notification\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AttendanceCorrectionService
{
    public function __construct(
        private readonly AttendanceCalculationService $calculationService,
        private readonly NotificationService $notifications,
    ) {}

    private function ensurePending(
        AttendanceCorrectionRequest $request
    ): void {
        if ($request->status !== 'pending') {
            throw new RuntimeException(
                'Attendance correction request must be pending. '
                ."Current status: {$request->status}."
            );
        }
    }

    public function submit(
        Employee $employee,
        User $createdBy,
        string $attendanceDate,
        ?string $requestedTimeIn,
        ?string $requestedTimeOut,
        string $reason,
        ?int $dailyAttendanceId = null,
        ?string $documentPath = null
    ): AttendanceCorrectionRequest {
        return DB::transaction(function () use ($employee, $createdBy, $attendanceDate, $requestedTimeIn, $requestedTimeOut, $reason, $dailyAttendanceId, $documentPath): AttendanceCorrectionRequest {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $date = CarbonImmutable::parse($attendanceDate)->startOfDay();
            $timeIn = $requestedTimeIn === null ? null : CarbonImmutable::parse($requestedTimeIn);
            $timeOut = $requestedTimeOut === null ? null : CarbonImmutable::parse($requestedTimeOut);

            if ($timeIn === null && $timeOut === null) {
                throw new RuntimeException('Attendance correction must contain a requested time in or time out.');
            }

            if ($timeIn !== null && $timeOut !== null && $timeOut->lt($timeIn)) {
                throw new RuntimeException('Corrected time out cannot be earlier than corrected time in.');
            }

            $reason = trim($reason);

            if ($reason === '') {
                throw new RuntimeException('Attendance correction reason must not be empty.');
            }

            $query = DailyAttendance::query()->where('employee_id', $employee->id)->whereDate('attendance_date', $date);

            if ($dailyAttendanceId !== null) {
                $query->whereKey($dailyAttendanceId);
            }

            $attendance = $query->lockForUpdate()->first();

            if ($dailyAttendanceId !== null && $attendance === null) {
                throw new RuntimeException('Daily attendance record does not match the employee and date.');
            }

            $request = AttendanceCorrectionRequest::query()->create([
                'employee_id' => $employee->id,
                'daily_attendance_id' => $attendance?->id,
                'attendance_date' => $date->toDateString(),
                'requested_time_in' => $timeIn,
                'requested_time_out' => $timeOut,
                'reason' => $reason,
                'document_path' => $documentPath,
                'status' => 'pending',
                'created_by_user_id' => $createdBy->id,
            ]);

            $this->notifications->queue($request, 'pending');

            return $request->fresh(['employee', 'dailyAttendance', 'creator', 'approvalActions']);
        });
    }

    public function approve(
        AttendanceCorrectionRequest $request,
        User $reviewer,
        ?string $remarks = null
    ): AttendanceCorrectionRequest {
        return DB::transaction(function () use ($request, $reviewer, $remarks) {
            $request = AttendanceCorrectionRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->id);
            $this->ensurePending($request);

            if (
                $request->requested_time_in === null
                && $request->requested_time_out === null
            ) {
                throw new RuntimeException(
                    'Attendance correction must contain a requested time in or time out.'
                );
            }

            $attendanceQuery = DailyAttendance::query()
                ->where('employee_id', $request->employee_id)
                ->whereDate('attendance_date', $request->attendance_date);

            if ($request->daily_attendance_id) {
                $attendanceQuery->whereKey($request->daily_attendance_id);
            }

            $attendance = $attendanceQuery
                ->lockForUpdate()
                ->first();

            if (! $attendance) {
                throw new RuntimeException(
                    'Daily attendance record was not found for this correction request.'
                );
            }

            $actualIn = $request->requested_time_in
                ?? $attendance->actual_in;

            $actualOut = $request->requested_time_out
                ?? $attendance->actual_out;

            if ($actualIn && $actualOut && $actualOut->lessThan($actualIn)) {
                throw new RuntimeException(
                    'Corrected time out cannot be earlier than corrected time in.'
                );
            }

            if ($attendance->shift) {
                if (! $attendance->scheduled_in || ! $attendance->scheduled_out) {
                    throw new RuntimeException(
                        'Scheduled attendance window is missing.'
                    );
                }

                $minutes = $this->calculationService->calculateMinutes(
                    $attendance->shift,
                    $attendance->scheduled_in,
                    $attendance->scheduled_out,
                    $actualIn,
                    $actualOut
                );
            } else {
                $minutes = [
                    'worked_minutes' => ($actualIn && $actualOut)
                        ? (int) $actualIn->diffInMinutes($actualOut)
                        : 0,
                    'late_minutes' => 0,
                    'undertime_minutes' => 0,
                    'overtime_minutes' => 0,
                ];
            }

            $status = $this->calculationService->determineStatus(
                $actualIn,
                $actualOut,
                $attendance->is_rest_day,
                $attendance->is_holiday
            );

            $attendance->update([
                'actual_in' => $actualIn,
                'actual_out' => $actualOut,
                'worked_minutes' => $minutes['worked_minutes'],
                'late_minutes' => $minutes['late_minutes'],
                'undertime_minutes' => $minutes['undertime_minutes'],
                'overtime_minutes' => $minutes['overtime_minutes'],
                'status' => $status,
                'calculated_at' => now(),
            ]);

            $request->update([
                'daily_attendance_id' => $attendance->id,
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
                'dailyAttendance',
                'approvalActions',
            ]);
        });
    }

    public function reject(
        AttendanceCorrectionRequest $request,
        User $reviewer,
        ?string $remarks = null
    ): AttendanceCorrectionRequest {
        return DB::transaction(function () use ($request, $reviewer, $remarks) {
            $request = AttendanceCorrectionRequest::query()
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
                'dailyAttendance',
                'approvalActions',
            ]);
        });
    }

    public function cancel(
        AttendanceCorrectionRequest $request,
        User $actor,
        ?string $reason = null
    ): AttendanceCorrectionRequest {
        return DB::transaction(function () use ($request, $actor, $reason) {
            $request = AttendanceCorrectionRequest::query()
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
                'dailyAttendance',
                'approvalActions',
            ]);
        });
    }
}
