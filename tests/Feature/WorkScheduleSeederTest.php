<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\Shift;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleDay;
use App\Services\Attendance\AttendanceCalculationService;
use App\Services\Attendance\EmployeeScheduleResolver;
use Database\Seeders\WorkScheduleSeeder;
use Illuminate\Database\Eloquent\Model;
use Tests\FormRequestTestCase;

class WorkScheduleSeederTest extends FormRequestTestCase
{
    public function test_standalone_seeding_creates_complete_common_schedules_without_assigning_employees(): void
    {
        $this->freezeTime();
        $employee = $this->employee();
        $employeeBefore = $employee->refresh()->getAttributes();

        $this->seed(WorkScheduleSeeder::class);

        $this->assertDatabaseCount('shifts', 5);
        $this->assertDatabaseCount('work_schedules', 6);
        $this->assertDatabaseCount('work_schedule_days', 42);
        $this->assertDatabaseCount('employee_schedule_assignments', 0);
        $this->assertDatabaseCount('employees', 1);
        $this->assertSame($employeeBefore, $employee->refresh()->getAttributes());

        $shiftTimes = [
            'STD-OFFICE-0800' => ['08:00:00', '17:00:00', false],
            'STD-OFFICE-0900' => ['09:00:00', '18:00:00', false],
            'STD-MORNING-0600' => ['06:00:00', '15:00:00', false],
            'STD-AFTERNOON-1400' => ['14:00:00', '23:00:00', false],
            'STD-NIGHT-2200' => ['22:00:00', '07:00:00', true],
        ];
        foreach ($shiftTimes as $code => [$start, $end, $overnight]) {
            $this->assertDatabaseHas('shifts', [
                'code' => $code,
                'start_time' => $start,
                'end_time' => $end,
                'break_minutes' => 60,
                'late_grace_minutes' => 0,
                'undertime_grace_minutes' => 0,
                'is_overnight' => $overnight,
                'is_active' => true,
            ]);
        }

        $schedulePatterns = [
            'STD-MF-OFFICE-0800' => ['STD-OFFICE-0800', [1, 2, 3, 4, 5]],
            'STD-MF-OFFICE-0900' => ['STD-OFFICE-0900', [1, 2, 3, 4, 5]],
            'STD-MS-OFFICE-0800' => ['STD-OFFICE-0800', [1, 2, 3, 4, 5, 6]],
            'STD-MS-MORNING-0600' => ['STD-MORNING-0600', [1, 2, 3, 4, 5, 6]],
            'STD-MF-AFTERNOON-1400' => ['STD-AFTERNOON-1400', [1, 2, 3, 4, 5]],
            'STD-MF-NIGHT-2200' => ['STD-NIGHT-2200', [1, 2, 3, 4, 5]],
        ];
        foreach ($schedulePatterns as $code => [$shiftCode, $workingDays]) {
            $schedule = WorkSchedule::query()->with('days.shift')->where('code', $code)->firstOrFail();
            $days = $schedule->days->sortBy('day_of_week')->values();
            $this->assertTrue($schedule->is_active);
            $this->assertSame([1, 2, 3, 4, 5, 6, 7], $days->pluck('day_of_week')->all());
            $this->assertSame($workingDays, $days->where('is_working_day', true)->pluck('day_of_week')->all());
            foreach ($days as $day) {
                if ($day->is_working_day) {
                    $this->assertSame($shiftCode, $day->shift?->code);
                } else {
                    $this->assertNull($day->shift_id);
                }
            }
        }
    }

    public function test_repeated_seeding_preserves_customizations_missing_days_unrelated_records_and_timestamps(): void
    {
        $this->travelTo('2026-10-05 08:00:00');
        $this->seed(WorkScheduleSeeder::class);
        $shift = Shift::query()->where('code', 'STD-OFFICE-0800')->firstOrFail();
        $shift->update([
            'name' => 'HR customized office shift',
            'start_time' => '07:00:00',
            'end_time' => '16:00:00',
            'break_minutes' => 30,
            'late_grace_minutes' => 10,
            'undertime_grace_minutes' => 5,
            'is_active' => false,
            'remarks' => 'Retain the existing shift policy.',
        ]);
        $schedule = WorkSchedule::query()->where('code', 'STD-MF-OFFICE-0800')->firstOrFail();
        $schedule->update([
            'name' => 'HR customized week',
            'description' => 'Retain these customized days.',
            'is_active' => false,
        ]);
        $schedule->days()->where('day_of_week', 1)->firstOrFail()->update([
            'is_working_day' => false,
            'shift_id' => null,
        ]);
        $schedule->days()->where('day_of_week', 7)->delete();
        $unrelatedShift = Shift::query()->create([
            'code' => 'CUSTOM-SHIFT',
            'name' => 'Existing custom shift',
            'start_time' => '10:00:00',
            'end_time' => '19:00:00',
            'break_minutes' => 60,
        ]);
        $unrelatedSchedule = WorkSchedule::query()->create([
            'code' => 'CUSTOM-WEEK',
            'name' => 'Existing custom week',
        ]);
        $unrelatedSchedule->days()->create([
            'day_of_week' => 3,
            'is_working_day' => true,
            'shift_id' => $unrelatedShift->id,
        ]);
        EmployeeScheduleAssignment::query()->create([
            'employee_id' => $this->employee()->id,
            'work_schedule_id' => $unrelatedSchedule->id,
            'effective_from' => '2026-10-01',
            'remarks' => 'Existing assignment must remain unchanged.',
        ]);
        $before = $this->scheduleRecords();
        $this->travelTo('2026-10-06 08:00:00');

        $this->seed(WorkScheduleSeeder::class);

        $this->assertSame($before, $this->scheduleRecords());
    }

    public function test_seeded_night_schedule_resolves_next_day_end_and_eight_worked_hours_after_break(): void
    {
        $this->freezeTime();
        $this->seed(WorkScheduleSeeder::class);
        $employee = $this->employee();
        $schedule = WorkSchedule::query()->where('code', 'STD-MF-NIGHT-2200')->firstOrFail();
        EmployeeScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'work_schedule_id' => $schedule->id,
            'effective_from' => '2026-10-01',
        ]);
        $resolver = $this->app->make(EmployeeScheduleResolver::class);
        $calculation = $this->app->make(AttendanceCalculationService::class);

        $workingDay = $resolver->resolve($employee, '2026-10-05');
        $restDay = $resolver->resolve($employee, '2026-10-11');
        $window = $calculation->scheduledWindow($workingDay['shift'], '2026-10-05');
        $minutes = $calculation->calculateMinutes(
            $workingDay['shift'],
            $window['scheduled_in'],
            $window['scheduled_out'],
            $window['scheduled_in']->copy(),
            $window['scheduled_out']->copy(),
        );

        $this->assertFalse($workingDay['is_rest_day']);
        $this->assertSame('STD-NIGHT-2200', $workingDay['shift']->code);
        $this->assertSame('2026-10-05 22:00:00', $window['scheduled_in']->toDateTimeString());
        $this->assertSame('2026-10-06 07:00:00', $window['scheduled_out']->toDateTimeString());
        $this->assertSame([
            'worked_minutes' => 480,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'overtime_minutes' => 0,
        ], $minutes);
        $this->assertTrue($restDay['is_rest_day']);
        $this->assertNull($restDay['shift']);
    }

    public function test_existing_inactive_shift_is_preserved_and_its_new_schedule_stays_inactive(): void
    {
        $this->travelTo('2026-10-05 08:00:00');
        $shift = Shift::query()->create([
            'code' => 'STD-NIGHT-2200',
            'name' => 'HR disabled night shift',
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'break_minutes' => 30,
            'is_overnight' => true,
            'is_active' => false,
        ]);
        $before = $shift->refresh()->getAttributes();
        $this->travelTo('2026-10-06 08:00:00');

        $this->seed(WorkScheduleSeeder::class);

        $this->assertSame($before, $shift->refresh()->getAttributes());
        $schedule = WorkSchedule::query()->with('days')->where('code', 'STD-MF-NIGHT-2200')->firstOrFail();
        $this->assertFalse($schedule->is_active);
        $this->assertCount(7, $schedule->days);
        $this->assertSame([$shift->id], $schedule->days->where('is_working_day', true)->pluck('shift_id')->unique()->values()->all());
        $this->assertDatabaseHas('work_schedules', ['code' => 'STD-MF-OFFICE-0800', 'is_active' => true]);
        $this->assertDatabaseCount('employee_schedule_assignments', 0);
    }

    /** @return array<class-string<Model>, list<array<string, mixed>>> */
    private function scheduleRecords(): array
    {
        $records = [];
        foreach ([Shift::class, WorkSchedule::class, WorkScheduleDay::class, Employee::class, EmployeeScheduleAssignment::class] as $model) {
            $records[$model] = $model::query()->orderBy('id')->get()->map(
                static fn (Model $record): array => $record->getAttributes(),
            )->all();
        }

        return $records;
    }
}
