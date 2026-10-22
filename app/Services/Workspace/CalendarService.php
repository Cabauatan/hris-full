<?php

namespace App\Services\Workspace;

use App\Models\EmployeeScheduleAssignment;
use App\Models\Holiday;
use App\Models\User;
use App\Services\Attendance\AttendanceCalculationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CalendarService
{
    private const EVENT_LIMIT = 500;

    private const ASSIGNMENT_LIMIT = 5000;

    private const EXPANSION_LIMIT = 20000;

    public function __construct(
        private readonly WorkspaceReadScope $visibility,
        private readonly AttendanceCalculationService $attendance,
        private readonly BirthdayScope $birthdays,
    ) {}

    /** @return array<string, mixed> */
    public function get(User $user, array $filters = []): array
    {
        $context = $this->visibility->context($user, $filters);
        $allowed = $this->allowedTypes($user);
        $selected = array_key_exists('types', $filters) ? array_values(array_intersect($allowed, $filters['types'])) : $allowed;
        $events = [];
        $warnings = [];
        $truncated = false;

        if (in_array('holiday', $selected, true)) {
            $holidays = Holiday::query()->where('is_active', true)->whereDate('holiday_date', '>=', $context['range']['start'])->whereDate('holiday_date', '<=', $context['range']['end'])->orderBy('holiday_date')->orderBy('id')->limit(self::EVENT_LIMIT + 1)->get();

            foreach ($holidays as $holiday) {
                $events[] = [
                    'id' => 'holiday:'.$holiday->id, 'type' => 'holiday', 'title' => $holiday->name,
                    'start' => $holiday->holiday_date->toDateString(), 'end' => $holiday->holiday_date->copy()->addDay()->toDateString(),
                    'all_day' => true, 'status' => null, 'employee' => null, 'source' => ['module' => 'holidays', 'id' => $holiday->id],
                    'details' => ['holiday_type' => $holiday->holiday_type, 'is_working_day' => $holiday->is_working_day, 'is_paid' => $holiday->is_paid],
                ];
            }
        }

        if (in_array('birthday', $selected, true)) {
            $this->addBirthdays($user, $context, $events);
        }

        foreach ($this->visibility->workflows() as $type => $workflow) {
            if (! in_array($type, $selected, true)) {
                continue;
            }

            $query = $this->visibility->workflowQuery($user, $workflow, $context['scope'])->where('status', 'approved');
            $query = $this->visibility->inRange($query, $workflow['date_kind'], $context['range']);
            $records = $query->with('employee:id,first_name,last_name,employee_number')->orderBy($workflow['date_kind'] === 'date' ? 'start_date' : 'start_at')->orderBy('id')->limit(self::EVENT_LIMIT + 1)->get();

            foreach ($records as $record) {
                $events[] = $this->workflowEvent($record, $type, $workflow);
            }
        }

        if (in_array('schedule', $selected, true)) {
            $this->addSchedules($user, $context, $events, $warnings, $truncated);
        }

        usort($events, fn (array $left, array $right): int => [$left['start'], $left['type'], $left['id']] <=> [$right['start'], $right['type'], $right['id']]);

        if (count($events) > self::EVENT_LIMIT) {
            $truncated = true;
            $warnings[] = 'The calendar contains more than 500 events. Narrow the date range or event types to see the remaining events.';
        }

        $warnings = array_values(array_unique($warnings));

        if (count($warnings) > 50) {
            $remainingWarnings = count($warnings) - 50;
            $warnings = array_slice($warnings, 0, 50);
            $warnings[] = $remainingWarnings.' additional schedule configuration warnings were omitted.';
        }

        return $context + ['allowed_types' => $allowed, 'events' => array_slice($events, 0, self::EVENT_LIMIT), 'meta' => ['truncated' => $truncated, 'warnings' => $warnings]];
    }

    /** @return list<string> */
    private function allowedTypes(User $user): array
    {
        $types = [];

        if ($user->can('viewCalendarType', 'birthday')) {
            $types[] = 'birthday';
        }

        if ($user->hasPermission('holidays.view')) {
            $types[] = 'holiday';
        }

        if ($user->hasPermission('schedules.view') || $user->hasPermission('schedules.assign')) {
            $types[] = 'schedule';
        }

        foreach ($this->visibility->workflows() as $type => $workflow) {
            if (in_array($type, ['leave', 'cto', 'overtime', 'official_business', 'travel_order'], true) && $user->hasPermission($workflow['permission'].'.view')) {
                $types[] = $type;
            }
        }

        return $types;
    }

    private function addBirthdays(User $user, array $context, array &$events): void
    {
        $start = CarbonImmutable::parse($context['range']['start'], config('app.timezone'))->startOfDay();
        $end = CarbonImmutable::parse($context['range']['end'], config('app.timezone'))->startOfDay();
        $dates = [];

        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $dates[] = [$date->year, $date->month, $date->day];

            if ($date->month === 2 && $date->day === 28 && ! $date->isLeapYear()) {
                $dates[] = [$date->year, 2, 29];
            }
        }

        $orderCases = [];
        $orderBindings = [];

        foreach ($dates as $index => [$year, $month, $day]) {
            $orderCases[] = 'WHEN birth_date LIKE ? THEN ?';
            $orderBindings[] = sprintf('%%-%02d-%02d%%', $month, $day);
            $orderBindings[] = $index;
        }

        $employees = $this->birthdays->calendarEmployees($user, $context['scope'])
            ->select(['id', 'first_name', 'last_name', 'employee_number', 'birth_date'])
            ->where('is_active', true)
            ->whereNotNull('birth_date')
            ->where(function (Builder $employees) use ($dates): void {
                foreach ($dates as [$year, $month, $day]) {
                    $employees->orWhere(fn (Builder $birthday): Builder => $birthday
                        ->whereYear('birth_date', '<', $year)
                        ->whereMonth('birth_date', $month)
                        ->whereDay('birth_date', $day));
                }
            })
            ->orderByRaw('CASE '.implode(' ', $orderCases).' END', $orderBindings)
            ->orderBy('id')->limit(self::EVENT_LIMIT + 1)->get();

        foreach ($employees as $employee) {
            for ($year = $start->year; $year <= $end->year; $year++) {
                $month = CarbonImmutable::create($year, $employee->birth_date->month, 1, 0, 0, 0, config('app.timezone'));
                $birthday = $month->addDays(min($employee->birth_date->day, $month->daysInMonth) - 1);
                $age = $year - $employee->birth_date->year;

                if ($age <= 0 || $birthday->lessThan($start) || $birthday->greaterThan($end)) {
                    continue;
                }

                $label = $this->visibility->employeeLabel($employee);
                $events[] = [
                    'id' => 'birthday:'.$employee->id.':'.$year, 'type' => 'birthday', 'title' => 'Birthday · '.$label['name'],
                    'start' => $birthday->toDateString(), 'end' => $birthday->addDay()->toDateString(),
                    'all_day' => true, 'status' => null, 'employee' => $label, 'source' => ['module' => 'employees', 'id' => $employee->id],
                    'details' => ['age' => $age],
                ];
            }
        }
    }

    /** @param array<string, mixed> $workflow
     * @return array<string, mixed>
     */
    private function workflowEvent(Model $record, string $type, array $workflow): array
    {
        $allDay = $workflow['date_kind'] === 'date';
        $employee = $this->visibility->employeeLabel($record->employee);
        $details = [];

        if ($allDay) {
            $details = ['day_portion' => $record->day_portion, 'approved_units' => $record->approved_units];
        } elseif (in_array($type, ['cto', 'overtime'], true)) {
            $details = ['approved_minutes' => $record->approved_minutes];
        }

        return [
            'id' => $type.':'.$record->id, 'type' => $type, 'title' => $workflow['label'].($employee === null ? '' : ' · '.$employee['name']),
            'start' => $allDay ? $record->start_date->toDateString() : $record->start_at->toAtomString(),
            'end' => $allDay ? $record->end_date->copy()->addDay()->toDateString() : $record->end_at->toAtomString(),
            'all_day' => $allDay, 'status' => $record->status, 'employee' => $employee, 'source' => ['module' => $workflow['module'], 'id' => $record->id], 'details' => $details,
        ];
    }

    private function addSchedules(User $user, array $context, array &$events, array &$warnings, bool &$truncated): void
    {
        $start = CarbonImmutable::parse($context['range']['start'], config('app.timezone'))->startOfDay();
        $endExclusive = CarbonImmutable::parse($context['range']['end_exclusive'], config('app.timezone'))->startOfDay();
        $firstDate = $start->subDay()->toDateString();
        $query = $this->visibility->records($user, EmployeeScheduleAssignment::query(), $context['scope'], $user->hasPermission('schedules.assign'));
        $assignments = $query->with(['employee:id,first_name,last_name,employee_number', 'workSchedule.days.shift'])
            ->whereDate('effective_from', '<=', $context['range']['end'])
            ->where(fn (Builder $query): Builder => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $firstDate))
            ->whereHas('workSchedule', fn (Builder $schedules): Builder => $schedules->where('is_active', true))
            ->orderByDesc('effective_from')->orderByDesc('id')->limit(self::ASSIGNMENT_LIMIT + 1)->get();

        if ($assignments->count() > self::ASSIGNMENT_LIMIT) {
            $truncated = true;
            $warnings[] = 'The schedule assignment limit was reached. Narrow the date range to show all effective schedules.';
            $assignments = $assignments->take(self::ASSIGNMENT_LIMIT);
        }

        $groups = $assignments->groupBy('employee_id');
        $expansions = 0;

        for ($date = $start->subDay(); $date->lessThan($endExclusive); $date = $date->addDay()) {
            foreach ($groups as $employeeAssignments) {
                $expansions++;

                if ($expansions > self::EXPANSION_LIMIT || count($events) > self::EVENT_LIMIT * 8) {
                    $truncated = true;
                    $warnings[] = 'The schedule expansion limit was reached. Narrow the date range or select fewer event types.';

                    return;
                }

                $dateString = $date->toDateString();
                $assignment = $employeeAssignments->first(fn (EmployeeScheduleAssignment $assignment): bool => $assignment->effective_from->toDateString() <= $dateString && ($assignment->effective_to === null || $assignment->effective_to->toDateString() >= $dateString));

                if ($assignment === null) {
                    continue;
                }

                $day = $assignment->workSchedule->days->firstWhere('day_of_week', $date->isoWeekday());

                if ($day === null || ($day->is_working_day && ($day->shift === null || ! $day->shift->is_active))) {
                    $employee = $assignment->employee;
                    $employeeLabel = $employee === null ? 'Employee unavailable' : trim($employee->first_name.' '.$employee->last_name).' ('.$employee->employee_number.')';
                    $warnings[] = $assignment->workSchedule->name.' for '.$employeeLabel.' has an incomplete or inactive shift configuration for '.$dateString.'.';

                    continue;
                }

                $isRestDay = ! $day->is_working_day;

                if ($isRestDay) {
                    if ($date->lessThan($start)) {
                        continue;
                    }

                    $eventStart = $dateString;
                    $eventEnd = $date->addDay()->toDateString();
                } else {
                    $window = $this->attendance->scheduledWindow($day->shift, $date);

                    if ($window['scheduled_out']->lessThanOrEqualTo($start) || $window['scheduled_in']->greaterThanOrEqualTo($endExclusive)) {
                        continue;
                    }

                    $eventStart = $window['scheduled_in']->toAtomString();
                    $eventEnd = $window['scheduled_out']->toAtomString();
                }

                $employee = $this->visibility->employeeLabel($assignment->employee);
                $events[] = [
                    'id' => 'schedule:'.$assignment->id.':'.$dateString, 'type' => 'schedule',
                    'title' => ($isRestDay ? 'Rest day' : $day->shift->name).($employee === null ? '' : ' · '.$employee['name']),
                    'start' => $eventStart, 'end' => $eventEnd, 'all_day' => $isRestDay, 'status' => $isRestDay ? 'rest_day' : 'scheduled',
                    'employee' => $employee, 'source' => ['module' => 'employee-schedule-assignments', 'id' => $assignment->id],
                    'details' => ['work_schedule_id' => $assignment->work_schedule_id, 'work_schedule_name' => $assignment->workSchedule->name, 'shift_id' => $isRestDay ? null : $day->shift->id, 'shift_name' => $isRestDay ? null : $day->shift->name, 'is_rest_day' => $isRestDay],
                ];
            }
        }
    }
}
