<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use RuntimeException;

class EmployeeScheduleResolver
{
    public function resolve(
        Employee $employee,
        CarbonInterface|string $date
    ): ?array {
        $targetDate = Carbon::parse($date)->startOfDay();

        $assignment = EmployeeScheduleAssignment::query()
            ->with([
                'workSchedule.days.shift',
            ])
            ->where('employee_id', $employee->id)
            ->whereDate('effective_from', '<=', $targetDate->toDateString())
            ->where(function ($query) use ($targetDate) {
                $query->whereNull('effective_to')
                    ->orWhereDate(
                        'effective_to',
                        '>=',
                        $targetDate->toDateString()
                    );
            })
            ->whereHas('workSchedule', function ($query) {
                $query->where('is_active', true);
            })
            ->latest('effective_from')
            ->latest('id')
            ->first();

        if (! $assignment) {
            return null;
        }

        $day = $assignment->workSchedule->days
            ->firstWhere('day_of_week', $targetDate->isoWeekday());

        if (! $day) {
            throw new RuntimeException(
                'Work schedule has no configuration for the target day. '
                ."Work schedule ID: {$assignment->work_schedule_id}, "
                ."day of week: {$targetDate->isoWeekday()}."
            );
        }

        $isRestDay = ! $day->is_working_day;
        if (! $isRestDay && ! $day->shift) {
            throw new RuntimeException(
                'Working schedule day has no assigned shift. '
                ."Work schedule ID: {$assignment->work_schedule_id}, "
                ."day of week: {$targetDate->isoWeekday()}."
            );
        }
        if (! $isRestDay && ! $day->shift->is_active) {
            throw new RuntimeException(
                'Working schedule day is assigned to an inactive shift. '
                ."Shift ID: {$day->shift_id}."
            );
        }

        return [
            'assignment' => $assignment,
            'work_schedule' => $assignment->workSchedule,
            'schedule_day' => $day,
            'shift' => $isRestDay ? null : $day->shift,
            'is_rest_day' => $isRestDay,
        ];

    }
}
