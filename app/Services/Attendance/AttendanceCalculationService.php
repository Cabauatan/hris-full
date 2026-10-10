<?php

namespace App\Services\Attendance;

use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\Shift;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AttendanceCalculationService
{
    public function scheduledWindow(
        Shift $shift,
        CarbonInterface|string $attendanceDate
    ): array {
        $date = Carbon::parse($attendanceDate)->startOfDay();

        $scheduledIn = $date->copy()->setTimeFromTimeString(
            $shift->start_time
        );

        $scheduledOut = $date->copy()->setTimeFromTimeString(
            $shift->end_time
        );

        if ($shift->is_overnight) {
            $scheduledOut->addDay();
        }

        return [
            'scheduled_in' => $scheduledIn,
            'scheduled_out' => $scheduledOut,
        ];
    }

    public function resolveActualTimes(Collection $timeLogs): array
    {
        $validLogs = $timeLogs
            ->filter(fn ($log) => $log->is_valid)
            ->sortBy('logged_at')
            ->values();

        if ($validLogs->isEmpty()) {
            return [
                'actual_in' => null,
                'actual_out' => null,
            ];
        }

        $actualIn = Carbon::parse($validLogs->first()->logged_at);

        $actualOut = $validLogs->count() >= 2
            ? Carbon::parse($validLogs->last()->logged_at)
            : null;

        return [
            'actual_in' => $actualIn,
            'actual_out' => $actualOut,
        ];
    }

    public function calculateMinutes(
        Shift $shift,
        CarbonInterface $scheduledIn,
        CarbonInterface $scheduledOut,
        ?CarbonInterface $actualIn,
        ?CarbonInterface $actualOut
    ): array {
        if (! $actualIn || ! $actualOut) {
            return [
                'worked_minutes' => 0,
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'overtime_minutes' => 0,
            ];
        }

        $workedMinutes = max(
            0,
            $actualIn->diffInMinutes($actualOut) - $shift->break_minutes
        );

        $rawLateMinutes = $actualIn->greaterThan($scheduledIn)
            ? $scheduledIn->diffInMinutes($actualIn)
            : 0;

        $lateMinutes = $rawLateMinutes > $shift->late_grace_minutes
            ? $rawLateMinutes
            : 0;

        $rawUndertimeMinutes = $actualOut->lessThan($scheduledOut)
            ? $actualOut->diffInMinutes($scheduledOut)
            : 0;

        $undertimeMinutes = $rawUndertimeMinutes > $shift->undertime_grace_minutes
            ? $rawUndertimeMinutes
            : 0;

        $overtimeMinutes = $actualOut->greaterThan($scheduledOut)
            ? $scheduledOut->diffInMinutes($actualOut)
            : 0;

        return [
            'worked_minutes' => (int) $workedMinutes,
            'late_minutes' => (int) $lateMinutes,
            'undertime_minutes' => (int) $undertimeMinutes,
            'overtime_minutes' => (int) $overtimeMinutes,
        ];
    }

    public function calculate(
        Shift $shift,
        CarbonInterface|string $attendanceDate,
        Collection $timeLogs,
        bool $isRestDay = false,
        bool $isHoliday = false,
        ?bool $isNonWorkingHoliday = null
    ): array {
        return $this->calculateFromActual(
            $shift,
            $attendanceDate,
            $this->resolveActualTimes($timeLogs),
            $isRestDay,
            $isHoliday,
            $isNonWorkingHoliday ?? $isHoliday
        );
    }

    private function calculateFromActual(
        Shift $shift,
        CarbonInterface|string $attendanceDate,
        array $actual,
        bool $isRestDay,
        bool $isHoliday,
        bool $isNonWorkingHoliday
    ): array {
        $window = $this->scheduledWindow($shift, $attendanceDate);

        $minutes = $this->calculateMinutes(
            $shift,
            $window['scheduled_in'],
            $window['scheduled_out'],
            $actual['actual_in'],
            $actual['actual_out']
        );

        $status = $this->determineStatus(
            $actual['actual_in'],
            $actual['actual_out'],
            $isRestDay,
            $isNonWorkingHoliday
        );

        return [
            'scheduled_in' => $window['scheduled_in'],
            'scheduled_out' => $window['scheduled_out'],
            'actual_in' => $actual['actual_in'],
            'actual_out' => $actual['actual_out'],
            ...$minutes,
            'status' => $status,
            'is_rest_day' => $isRestDay,
            'is_holiday' => $isHoliday,
            'calculated_at' => now(),
        ];
    }

    public function determineStatus(
        ?CarbonInterface $actualIn,
        ?CarbonInterface $actualOut,
        bool $isRestDay = false,
        bool $isHoliday = false
    ): string {
        if ($actualIn && $actualOut) {
            return 'present';
        }

        if ($actualIn || $actualOut) {
            return 'incomplete';
        }

        if ($isRestDay) {
            return 'rest_day';
        }

        if ($isHoliday) {
            return 'holiday';
        }

        return 'absent';
    }

    public function persist(
        Employee $employee,
        Shift $shift,
        CarbonInterface|string $attendanceDate,
        Collection $timeLogs,
        ?int $workScheduleId = null,
        bool $isRestDay = false,
        bool $isHoliday = false,
        ?bool $isNonWorkingHoliday = null
    ): DailyAttendance {
        $date = Carbon::parse($attendanceDate)->toDateString();

        return DB::transaction(function () use ($employee, $shift, $date, $timeLogs, $workScheduleId, $isRestDay, $isHoliday, $isNonWorkingHoliday): DailyAttendance {
            $attendance = $this->lockAttendance($employee, $date);
            $actual = $this->preserveApprovedTimes($attendance, $timeLogs);
            $result = $this->calculateFromActual(
                $shift,
                $date,
                $actual,
                $isRestDay,
                $isHoliday,
                $isNonWorkingHoliday ?? $isHoliday
            );

            $attendance->fill([
                'work_schedule_id' => $workScheduleId,
                'shift_id' => $shift->id,
                ...$result,
            ])->save();

            return $attendance;
        });
    }

    public function persistRestDay(
        Employee $employee,
        CarbonInterface|string $attendanceDate,
        Collection $timeLogs,
        ?int $workScheduleId = null,
        bool $isHoliday = false
    ): DailyAttendance {
        $date = Carbon::parse($attendanceDate)->toDateString();

        return DB::transaction(function () use ($employee, $date, $timeLogs, $workScheduleId, $isHoliday): DailyAttendance {
            $attendance = $this->lockAttendance($employee, $date);
            $actual = $this->preserveApprovedTimes($attendance, $timeLogs);
            $workedMinutes = $actual['actual_in'] && $actual['actual_out']
                ? (int) $actual['actual_in']->diffInMinutes($actual['actual_out'])
                : 0;

            $attendance->fill([
                'work_schedule_id' => $workScheduleId,
                'shift_id' => null,
                'scheduled_in' => null,
                'scheduled_out' => null,
                'actual_in' => $actual['actual_in'],
                'actual_out' => $actual['actual_out'],
                'worked_minutes' => $workedMinutes,
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'overtime_minutes' => 0,
                'status' => $this->determineStatus($actual['actual_in'], $actual['actual_out'], true, $isHoliday),
                'is_rest_day' => true,
                'is_holiday' => $isHoliday,
                'calculated_at' => now(),
            ])->save();

            return $attendance;
        });
    }

    private function lockAttendance(Employee $employee, string $date): DailyAttendance
    {
        Employee::query()->lockForUpdate()->findOrFail($employee->id);

        return DailyAttendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $date)
            ->lockForUpdate()
            ->first() ?? new DailyAttendance([
                'employee_id' => $employee->id,
                'attendance_date' => $date,
            ]);
    }

    private function preserveApprovedTimes(DailyAttendance $attendance, Collection $timeLogs): array
    {
        $actual = $this->resolveActualTimes($timeLogs);

        if ($attendance->exists) {
            $corrections = $attendance->correctionRequests()
                ->where('status', 'approved')
                ->get(['requested_time_in', 'requested_time_out']);

            // The locked attendance contains the latest applied approval, including partial corrections.
            if ($corrections->contains(fn ($correction) => $correction->requested_time_in !== null)) {
                $actual['actual_in'] = $attendance->actual_in;
            }

            if ($corrections->contains(fn ($correction) => $correction->requested_time_out !== null)) {
                $actual['actual_out'] = $attendance->actual_out;
            }
        }

        if ($actual['actual_in'] && $actual['actual_out'] && $actual['actual_out']->lessThan($actual['actual_in'])) {
            throw new RuntimeException('Attendance time out must not be before time in.');
        }

        return $actual;
    }
}
