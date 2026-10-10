<?php

namespace App\Services\Attendance;

use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Shift;
use App\Models\TimeLog;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use RuntimeException;

class AttendanceProcessingService
{
    public function __construct(
        private readonly EmployeeScheduleResolver $scheduleResolver,
        private readonly HolidayResolver $holidayResolver,
        private readonly AttendanceCalculationService $calculationService,
    ) {}

    public function timeLogWindow(
        Shift $shift,
        CarbonInterface|string $attendanceDate,
        int $preShiftBufferMinutes = 240,
        int $postShiftBufferMinutes = 360
    ): array {
        $scheduled = $this->calculationService->scheduledWindow(
            $shift,
            $attendanceDate
        );

        return [
            'from' => $scheduled['scheduled_in']
                ->copy()
                ->subMinutes($preShiftBufferMinutes),

            'to' => $scheduled['scheduled_out']
                ->copy()
                ->addMinutes($postShiftBufferMinutes),
        ];
    }

    public function timeLogsForShift(
        Employee $employee,
        Shift $shift,
        CarbonInterface|string $attendanceDate
    ): Collection {
        $window = $this->timeLogWindow(
            $shift,
            $attendanceDate
        );

        return TimeLog::query()
            ->where('employee_id', $employee->id)
            ->where('is_valid', true)
            ->whereBetween('logged_at', [
                $window['from'],
                $window['to'],
            ])
            ->orderBy('logged_at')
            ->get();
    }

    public function timeLogsForDate(
        Employee $employee,
        CarbonInterface|string $attendanceDate
    ): Collection {
        $date = Carbon::parse($attendanceDate);

        return TimeLog::query()
            ->where('employee_id', $employee->id)
            ->where('is_valid', true)
            ->whereBetween('logged_at', [
                $date->copy()->startOfDay(),
                $date->copy()->endOfDay(),
            ])
            ->orderBy('logged_at')
            ->get();
    }

    public function process(
        Employee $employee,
        CarbonInterface|string $attendanceDate
    ): DailyAttendance {
        $date = Carbon::parse($attendanceDate)->toDateString();

        $schedule = $this->scheduleResolver->resolve(
            $employee,
            $date
        );

        if (! $schedule) {
            throw new RuntimeException(
                "Employee has no effective work schedule for {$date}. "
                ."Employee ID: {$employee->id}."
            );
        }

        $holidays = $this->holidayResolver->resolve($date);
        $isHoliday = $holidays->isNotEmpty();
        $isNonWorkingHoliday = $holidays->contains(fn (Holiday $holiday) => ! $holiday->is_working_day);

        if ($schedule['is_rest_day']) {
            $timeLogs = $this->timeLogsForDate(
                $employee,
                $date
            );

            return $this->calculationService->persistRestDay(
                $employee,
                $date,
                $timeLogs,
                $schedule['work_schedule']->id,
                $isHoliday
            );
        }

        $shift = $schedule['shift'];

        $timeLogs = $this->timeLogsForShift(
            $employee,
            $shift,
            $date
        );

        return $this->calculationService->persist(
            $employee,
            $shift,
            $date,
            $timeLogs,
            $schedule['work_schedule']->id,
            false,
            $isHoliday,
            $isNonWorkingHoliday
        );
    }
}
