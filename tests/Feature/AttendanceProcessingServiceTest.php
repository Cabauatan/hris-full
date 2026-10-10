<?php

namespace Tests\Feature;

use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\Shift;
use App\Models\TimeLog;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleDay;
use App\Services\Attendance\AttendanceProcessingService;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\FormRequestTestCase;

class AttendanceProcessingServiceTest extends FormRequestTestCase
{
    private const DATE = '2026-10-05';

    public function test_overnight_processing_uses_next_day_scans_and_excludes_invalid_foreign_and_out_of_window_logs(): void
    {
        $this->travelTo('2026-10-06 13:00:00');
        $employee = $this->employee();
        $shift = $this->shift([
            'start_time' => '22:00:00', 'end_time' => '06:00:00',
            'is_overnight' => true, 'break_minutes' => 30,
        ]);
        $this->assign($employee, $shift);
        $this->scan($employee, '2026-10-05 22:16:00');
        $this->scan($employee, '2026-10-06 01:00:00');
        $this->scan($employee, '2026-10-06 06:31:00');
        $this->scan($employee, '2026-10-05 21:00:00', false);
        $this->scan($employee, '2026-10-06 08:00:00', false);
        $this->scan($employee, '2026-10-05 17:59:59');
        $this->scan($employee, '2026-10-06 12:00:01');
        $this->scan($this->employee(), '2026-10-05 18:05:00');

        $attendance = $this->process($employee);

        $this->assertSame('2026-10-05', $attendance->attendance_date->toDateString());
        $this->assertSame('2026-10-05 22:00:00', $attendance->scheduled_in->toDateTimeString());
        $this->assertSame('2026-10-06 06:00:00', $attendance->scheduled_out->toDateTimeString());
        $this->assertSame('2026-10-05 22:16:00', $attendance->actual_in->toDateTimeString());
        $this->assertSame('2026-10-06 06:31:00', $attendance->actual_out->toDateTimeString());
        $this->assertSame('present', $attendance->status);
        $this->assertSame(465, $attendance->worked_minutes);
        $this->assertSame(16, $attendance->late_minutes);
        $this->assertSame(0, $attendance->undertime_minutes);
        $this->assertSame(31, $attendance->overtime_minutes);
        $this->assertDatabaseCount('daily_attendances', 1);
    }

    #[DataProvider('graceBoundaries')]
    public function test_grace_limits_excuse_the_exact_boundary_and_record_the_full_delay_after_it(
        string $timeIn, string $timeOut, int $worked, int $late, int $undertime, int $overtime,
    ): void {
        $this->travelTo('2026-10-06 13:00:00');
        $employee = $this->employee();
        $this->assign($employee, $this->shift());
        $this->scan($employee, self::DATE.' '.$timeIn);
        $this->scan($employee, self::DATE.' '.$timeOut);

        $attendance = $this->process($employee);

        $this->assertSame('present', $attendance->status);
        $this->assertSame($worked, $attendance->worked_minutes);
        $this->assertSame($late, $attendance->late_minutes);
        $this->assertSame($undertime, $attendance->undertime_minutes);
        $this->assertSame($overtime, $attendance->overtime_minutes);
    }

    public static function graceBoundaries(): array
    {
        return [
            'late below grace' => ['08:14:00', '17:00:00', 466, 0, 0, 0],
            'late exactly grace' => ['08:15:00', '17:00:00', 465, 0, 0, 0],
            'late beyond grace' => ['08:16:00', '17:00:00', 464, 16, 0, 0],
            'undertime below grace' => ['08:00:00', '16:51:00', 471, 0, 0, 0],
            'undertime exactly grace' => ['08:00:00', '16:50:00', 470, 0, 0, 0],
            'undertime beyond grace' => ['08:00:00', '16:49:00', 469, 0, 11, 0],
            'one minute overtime' => ['08:00:00', '17:01:00', 481, 0, 0, 1],
            'break greater than attendance floors worked minutes' => ['08:00:00', '08:30:00', 0, 0, 510, 0],
        ];
    }

    public function test_reprocessing_updates_one_record_with_new_scans_without_duplicating_attendance(): void
    {
        $this->travelTo('2026-10-06 13:00:00');
        $employee = $this->employee();
        $this->assign($employee, $this->shift());
        $this->scan($employee, self::DATE.' 08:00:00');
        $this->scan($employee, self::DATE.' 17:00:00');
        $first = $this->process($employee);
        $this->scan($employee, self::DATE.' 17:30:00');
        $this->travel(1)->minutes();

        $second = $this->process($employee);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(510, $second->worked_minutes);
        $this->assertSame(30, $second->overtime_minutes);
        $this->assertSame('2026-10-06 13:01:00', $second->calculated_at->toDateTimeString());
        $this->assertDatabaseCount('daily_attendances', 1);
        $this->assertDatabaseCount('time_logs', 3);
    }

    public function test_effective_assignment_selection_includes_end_date_and_uses_latest_active_tie_breaker(): void
    {
        $this->travelTo('2026-10-06 13:00:00');
        $employee = $this->employee();
        $old = $this->shift();
        $chosen = $this->shift(['start_time' => '09:00:00', 'end_time' => '18:00:00']);
        $this->assign($employee, $old, ['effective_from' => '2026-01-01', 'effective_to' => self::DATE]);
        $this->assign($employee, $old, ['effective_from' => '2026-10-01']);
        $assignment = $this->assign($employee, $chosen, ['effective_from' => '2026-10-01', 'effective_to' => self::DATE]);
        $this->assign($employee, $old, ['effective_from' => '2026-10-06']);
        $inactive = $this->assign($employee, $old, ['effective_from' => self::DATE]);
        $inactive->workSchedule->update(['is_active' => false]);
        $this->scan($employee, self::DATE.' 09:00:00');
        $this->scan($employee, self::DATE.' 18:00:00');

        $attendance = $this->process($employee);

        $this->assertSame($assignment->work_schedule_id, $attendance->work_schedule_id);
        $this->assertSame($chosen->id, $attendance->shift_id);
        $this->assertSame('09:00:00', $attendance->scheduled_in->format('H:i:s'));
        $this->assertSame(480, $attendance->worked_minutes);
        $this->assertSame(0, $attendance->late_minutes);
        $this->assertSame(0, $attendance->undertime_minutes);
    }

    #[DataProvider('invalidSchedules')]
    public function test_invalid_effective_schedule_configuration_fails_without_creating_attendance(
        string $problem, string $message,
    ): void {
        $this->travelTo('2026-10-06 13:00:00');
        $employee = $this->employee();
        $this->invalidAssignment($employee, $problem);
        $this->scan($employee, self::DATE.' 08:00:00');

        try {
            $this->process($employee);
            $this->fail('Expected invalid schedules to fail without attendance writes.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        $this->assertDatabaseCount('daily_attendances', 0);
        $this->assertDatabaseCount('time_logs', 1);
    }

    public static function invalidSchedules(): array
    {
        return [
            'no assignment' => ['missing', 'no effective work schedule'],
            'expired assignment' => ['expired', 'no effective work schedule'],
            'inactive schedule' => ['inactive_schedule', 'no effective work schedule'],
            'missing day' => ['missing_day', 'no configuration for the target day'],
            'working day without shift' => ['missing_shift', 'no assigned shift'],
            'inactive shift' => ['inactive_shift', 'inactive shift'],
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function shift(array $attributes = []): Shift
    {
        return Shift::query()->create(array_replace([
            'code' => 'CALC-'.Str::uuid(), 'name' => 'Calculation shift',
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'break_minutes' => 60,
            'late_grace_minutes' => 15, 'undertime_grace_minutes' => 10,
            'is_overnight' => false, 'is_active' => true,
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function assign(Employee $employee, Shift $shift, array $attributes = []): EmployeeScheduleAssignment
    {
        $schedule = WorkSchedule::query()->create([
            'code' => 'CALC-'.Str::uuid(), 'name' => 'Calculation schedule', 'is_active' => true,
        ]);
        WorkScheduleDay::query()->create([
            'work_schedule_id' => $schedule->id, 'day_of_week' => 1,
            'is_working_day' => true, 'shift_id' => $shift->id,
        ]);

        return EmployeeScheduleAssignment::query()->create(array_replace([
            'employee_id' => $employee->id, 'work_schedule_id' => $schedule->id,
            'effective_from' => '2026-10-01', 'effective_to' => null,
        ], $attributes));
    }

    private function invalidAssignment(Employee $employee, string $problem): void
    {
        if ($problem === 'missing') {
            return;
        }

        $shift = $this->shift();
        $assignment = $this->assign($employee, $shift);

        match ($problem) {
            'expired' => $assignment->update(['effective_to' => '2026-10-04']),
            'inactive_schedule' => $assignment->workSchedule->update(['is_active' => false]),
            'missing_day' => $assignment->workSchedule->days()->delete(),
            'missing_shift' => $assignment->workSchedule->days()->update(['shift_id' => null]),
            'inactive_shift' => $shift->update(['is_active' => false]),
        };
    }

    private function scan(Employee $employee, string $loggedAt, bool $isValid = true): TimeLog
    {
        return TimeLog::query()->create([
            'employee_id' => $employee->id, 'logged_at' => $loggedAt,
            'source' => 'manual', 'is_valid' => $isValid,
        ]);
    }

    private function process(Employee $employee): DailyAttendance
    {
        return $this->app->make(AttendanceProcessingService::class)->process($employee, self::DATE);
    }
}
