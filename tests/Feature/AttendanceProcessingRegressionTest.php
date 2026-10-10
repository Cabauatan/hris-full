<?php

namespace Tests\Feature;

use App\Models\AttendanceCorrectionRequest;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\Holiday;
use App\Models\Shift;
use App\Models\TimeLog;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleDay;
use App\Services\Attendance\AttendanceProcessingService;
use App\Services\AttendanceCorrection\AttendanceCorrectionService;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\FormRequestTestCase;

class AttendanceProcessingRegressionTest extends FormRequestTestCase
{
    private const DATE = '2026-10-05';

    #[DataProvider('holidayStatuses')]
    public function test_missing_scans_respect_working_holidays_and_preserve_the_holiday_flag(
        bool $isWorkingHoliday, bool $isActive, bool $isRestDay, string $expectedStatus, bool $expectedHoliday,
    ): void {
        $this->travelTo('2026-10-06 09:00:00');
        [$employee] = $this->scheduledEmployee($isRestDay);
        $this->holiday($isWorkingHoliday, $isActive);

        $attendance = $this->process($employee);

        $this->assertSame($expectedStatus, $attendance->status);
        $this->assertSame($expectedHoliday, $attendance->is_holiday);
        $this->assertSame($isRestDay, $attendance->is_rest_day);
        $this->assertNull($attendance->actual_in);
        $this->assertNull($attendance->actual_out);
        $this->assertSame(0, $attendance->worked_minutes);
        $this->assertDatabaseCount('daily_attendances', 1);
    }

    public static function holidayStatuses(): array
    {
        return [
            'working holiday remains scheduled work day' => [true, true, false, 'absent', true],
            'nonworking holiday excuses missing scans' => [false, true, false, 'holiday', true],
            'inactive holiday has no effect' => [false, false, false, 'absent', false],
            'rest day retains priority on working holiday' => [true, true, true, 'rest_day', true],
            'rest day retains priority on nonworking holiday' => [false, true, true, 'rest_day', true],
        ];
    }

    #[DataProvider('rawAttendanceCases')]
    public function test_processing_preserves_normal_and_rest_day_raw_scan_statuses(
        bool $isRestDay, array $scans, string $status, int $workedMinutes,
    ): void {
        $this->travelTo('2026-10-06 09:00:00');
        [$employee] = $this->scheduledEmployee($isRestDay);
        foreach ($scans as $scan) {
            $this->scan($employee, $scan);
        }

        $attendance = $this->process($employee);

        $this->assertSame($status, $attendance->status);
        $this->assertSame($workedMinutes, $attendance->worked_minutes);
        $this->assertSame($isRestDay, $attendance->is_rest_day);
        $this->assertFalse($attendance->is_holiday);
    }

    public static function rawAttendanceCases(): array
    {
        return [
            'scheduled absent' => [false, [], 'absent', 0],
            'scheduled incomplete' => [false, ['09:00:00'], 'incomplete', 0],
            'scheduled present' => [false, ['08:00:00', '17:00:00'], 'present', 480],
            'rest day' => [true, [], 'rest_day', 0],
            'rest day incomplete' => [true, ['09:00:00'], 'incomplete', 0],
            'rest day present without break deduction' => [true, ['08:00:00', '17:00:00'], 'present', 540],
        ];
    }

    #[DataProvider('scheduleKinds')]
    public function test_reprocessing_keeps_approved_correction_times_and_recomputed_minutes(
        bool $isRestDay, int $workedMinutes,
    ): void {
        $this->travelTo('2026-10-06 09:00:00');
        [$employee, $owner] = $this->scheduledEmployee($isRestDay);
        $reviewer = $this->user();
        $this->scan($employee, '09:00:00');
        $attendance = $this->process($employee);
        $this->assertSame('incomplete', $attendance->status);
        $correction = $this->submitCorrection($employee, $owner, $attendance, '08:00:00', '17:00:00');
        $approved = $this->app->make(AttendanceCorrectionService::class)->approve($correction, $reviewer);

        $reprocessed = $this->process($employee);

        $this->assertSame($attendance->id, $reprocessed->id);
        $this->assertSame('08:00:00', $reprocessed->actual_in->format('H:i:s'));
        $this->assertSame('17:00:00', $reprocessed->actual_out->format('H:i:s'));
        $this->assertSame('present', $reprocessed->status);
        $this->assertSame($workedMinutes, $reprocessed->worked_minutes);
        $this->assertSame(0, $reprocessed->late_minutes);
        $this->assertSame(0, $reprocessed->undertime_minutes);
        $this->assertSame(0, $reprocessed->overtime_minutes);
        $this->assertSame('approved', $approved->fresh()->status);
        $this->assertCount(1, $approved->approvalActions()->get());
        $this->assertDatabaseCount('daily_attendances', 1);
    }

    public static function scheduleKinds(): array
    {
        return ['scheduled' => [false, 480], 'rest day' => [true, 540]];
    }

    public function test_latest_approval_wins_even_when_requests_were_created_in_reverse_order(): void
    {
        $this->travelTo('2026-10-06 09:00:00');
        [$employee, $owner] = $this->scheduledEmployee();
        $reviewer = $this->user();
        $this->scan($employee, '09:00:00');
        $attendance = $this->process($employee);
        $earlierCreated = $this->submitCorrection($employee, $owner, $attendance, '09:00:00', '16:00:00');
        $laterCreated = $this->submitCorrection($employee, $owner, $attendance, '08:15:00', '18:00:00');
        $service = $this->app->make(AttendanceCorrectionService::class);
        $service->approve($laterCreated, $reviewer);
        $this->travel(1)->minutes();
        $service->approve($earlierCreated, $reviewer);
        $this->travel(1)->minutes();
        $this->submitCorrection($employee, $owner, $attendance, '07:00:00', '19:00:00');

        $reprocessed = $this->process($employee);

        $this->assertSame('09:00:00', $reprocessed->actual_in->format('H:i:s'));
        $this->assertSame('16:00:00', $reprocessed->actual_out->format('H:i:s'));
        $this->assertSame('present', $reprocessed->status);
        $this->assertSame(360, $reprocessed->worked_minutes);
        $this->assertSame(60, $reprocessed->late_minutes);
        $this->assertSame(60, $reprocessed->undertime_minutes);
        $this->assertSame(0, $reprocessed->overtime_minutes);
        $this->assertDatabaseCount('approval_actions', 2);
    }

    public function test_last_applied_approval_wins_when_review_timestamps_are_identical(): void
    {
        $this->travelTo('2026-10-06 09:00:00');
        [$employee, $owner] = $this->scheduledEmployee();
        $reviewer = $this->user();
        $this->scan($employee, '09:00:00');
        $attendance = $this->process($employee);
        $earlierCreated = $this->submitCorrection($employee, $owner, $attendance, '09:00:00', '16:00:00');
        $laterCreated = $this->submitCorrection($employee, $owner, $attendance, '08:15:00', '18:00:00');
        $service = $this->app->make(AttendanceCorrectionService::class);
        $service->approve($laterCreated, $reviewer);
        $service->approve($earlierCreated, $reviewer);
        $this->assertSame($earlierCreated->fresh()->reviewed_at->toDateTimeString(), $laterCreated->fresh()->reviewed_at->toDateTimeString());

        $reprocessed = $this->process($employee);

        $this->assertSame('09:00:00', $reprocessed->actual_in->format('H:i:s'));
        $this->assertSame('16:00:00', $reprocessed->actual_out->format('H:i:s'));
        $this->assertSame('present', $reprocessed->status);
        $this->assertSame(360, $reprocessed->worked_minutes);
        $this->assertSame(60, $reprocessed->late_minutes);
        $this->assertSame(60, $reprocessed->undertime_minutes);
        $this->assertSame(0, $reprocessed->overtime_minutes);
        $this->assertDatabaseCount('approval_actions', 2);
    }

    #[DataProvider('unapprovedStatuses')]
    public function test_unapproved_corrections_do_not_override_raw_scans(string $status): void
    {
        $this->travelTo('2026-10-06 09:00:00');
        [$employee, $owner] = $this->scheduledEmployee();
        $this->scan($employee, '09:00:00');
        $attendance = $this->process($employee);
        $request = $this->submitCorrection($employee, $owner, $attendance, '08:00:00', '17:00:00');
        $request->update(['status' => $status]);

        $reprocessed = $this->process($employee);

        $this->assertSame('09:00:00', $reprocessed->actual_in->format('H:i:s'));
        $this->assertNull($reprocessed->actual_out);
        $this->assertSame('incomplete', $reprocessed->status);
        $this->assertSame(0, $reprocessed->worked_minutes);
        $this->assertDatabaseCount('approval_actions', 0);
    }

    public static function unapprovedStatuses(): array
    {
        return ['pending' => ['pending'], 'rejected' => ['rejected'], 'cancelled' => ['cancelled']];
    }

    public function test_partial_correction_preserves_the_other_raw_field_and_observes_new_valid_scans(): void
    {
        $this->travelTo('2026-10-06 09:00:00');
        [$employee, $owner] = $this->scheduledEmployee();
        $this->scan($employee, '09:00:00');
        $this->scan($employee, '16:00:00');
        $attendance = $this->process($employee);
        $correction = $this->submitCorrection($employee, $owner, $attendance, '08:00:00', null);
        $this->app->make(AttendanceCorrectionService::class)->approve($correction, $this->user());
        $this->scan($employee, '17:00:00');

        $reprocessed = $this->process($employee);

        $this->assertSame('08:00:00', $reprocessed->actual_in->format('H:i:s'));
        $this->assertSame('17:00:00', $reprocessed->actual_out->format('H:i:s'));
        $this->assertSame(480, $reprocessed->worked_minutes);
        $this->assertSame(0, $reprocessed->late_minutes);
        $this->assertSame(0, $reprocessed->undertime_minutes);
    }

    public function test_later_partial_correction_keeps_the_other_previously_approved_field(): void
    {
        $this->travelTo('2026-10-06 09:00:00');
        [$employee, $owner] = $this->scheduledEmployee();
        $reviewer = $this->user();
        $this->scan($employee, '09:00:00');
        $attendance = $this->process($employee);
        $service = $this->app->make(AttendanceCorrectionService::class);
        $service->approve($this->submitCorrection($employee, $owner, $attendance, '08:00:00', '17:00:00'), $reviewer);
        $this->travel(1)->minutes();
        $service->approve($this->submitCorrection($employee, $owner, $attendance, null, '17:30:00'), $reviewer);

        $reprocessed = $this->process($employee);

        $this->assertSame('08:00:00', $reprocessed->actual_in->format('H:i:s'));
        $this->assertSame('17:30:00', $reprocessed->actual_out->format('H:i:s'));
        $this->assertSame('present', $reprocessed->status);
        $this->assertSame(510, $reprocessed->worked_minutes);
        $this->assertSame(30, $reprocessed->overtime_minutes);
        $this->assertSame(0, $reprocessed->late_minutes);
        $this->assertSame(0, $reprocessed->undertime_minutes);
    }

    public function test_conflicting_new_raw_time_after_partial_approval_leaves_attendance_unchanged(): void
    {
        $this->travelTo('2026-10-06 09:00:00');
        [$employee, $owner] = $this->scheduledEmployee();
        $this->scan($employee, '09:00:00');
        $oldTimeOut = $this->scan($employee, '17:00:00');
        $attendance = $this->process($employee);
        $correction = $this->submitCorrection($employee, $owner, $attendance, '16:00:00', null);
        $this->app->make(AttendanceCorrectionService::class)->approve($correction, $this->user());
        $before = $attendance->fresh()->getAttributes();
        $oldTimeOut->update(['is_valid' => false]);
        $this->scan($employee, '15:00:00');
        $this->travel(1)->minutes();

        try {
            $this->process($employee);
            $this->fail('Expected conflicting scans to be rejected without replacing the approved attendance.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Attendance time out must not be before time in.', $exception->getMessage());
        }

        $this->assertSame($before, $attendance->fresh()->getAttributes());
        $this->assertSame('approved', $correction->fresh()->status);
        $this->assertDatabaseCount('daily_attendances', 1);
        $this->assertDatabaseCount('approval_actions', 1);
    }

    /** @return array{Employee, User} */
    private function scheduledEmployee(bool $isRestDay = false): array
    {
        $owner = $this->user();
        $employee = $this->employee($owner);
        $shift = Shift::query()->create([
            'code' => 'REG-'.Str::uuid(), 'name' => 'Regression day shift',
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'break_minutes' => 60,
            'late_grace_minutes' => 0, 'undertime_grace_minutes' => 0,
            'is_overnight' => false, 'is_active' => true,
        ]);
        $schedule = WorkSchedule::query()->create([
            'code' => 'REG-'.Str::uuid(), 'name' => 'Regression schedule', 'is_active' => true,
        ]);
        WorkScheduleDay::query()->create([
            'work_schedule_id' => $schedule->id, 'day_of_week' => 1,
            'is_working_day' => ! $isRestDay, 'shift_id' => $isRestDay ? null : $shift->id,
        ]);
        EmployeeScheduleAssignment::query()->create([
            'employee_id' => $employee->id, 'work_schedule_id' => $schedule->id,
            'effective_from' => '2026-10-01', 'effective_to' => null,
        ]);

        return [$employee, $owner];
    }

    private function scan(Employee $employee, string $time): TimeLog
    {
        return TimeLog::query()->create([
            'employee_id' => $employee->id, 'logged_at' => self::DATE.' '.$time,
            'source' => 'manual', 'is_valid' => true,
        ]);
    }

    private function holiday(bool $isWorkingDay, bool $isActive): Holiday
    {
        return Holiday::query()->create([
            'name' => 'Regression holiday', 'holiday_date' => self::DATE,
            'holiday_type' => $isWorkingDay ? 'special_working' : 'regular',
            'is_working_day' => $isWorkingDay, 'is_paid' => true, 'is_active' => $isActive,
        ]);
    }

    private function process(Employee $employee): DailyAttendance
    {
        return $this->app->make(AttendanceProcessingService::class)->process($employee, self::DATE);
    }

    private function submitCorrection(
        Employee $employee, User $owner, DailyAttendance $attendance, ?string $timeIn, ?string $timeOut,
    ): AttendanceCorrectionRequest {
        return $this->app->make(AttendanceCorrectionService::class)->submit(
            $employee, $owner, self::DATE,
            $timeIn === null ? null : self::DATE.' '.$timeIn,
            $timeOut === null ? null : self::DATE.' '.$timeOut,
            'Missing biometric scan.', $attendance->id,
        );
    }
}
