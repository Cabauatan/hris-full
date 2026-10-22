<?php

namespace App\Services\Workspace;

use App\Models\Applicant;
use App\Models\CtoRequest;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\EmployeeProfileChangeRequest;
use App\Models\JobApplication;
use App\Models\JobOpening;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\RecruitmentExam;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Pagination\AbstractPaginator;

class ResourceLabelService
{
    private const RELATIONS = [
        'employee_id' => 'employee',
        'supervisor_employee_id' => 'supervisor',
        'user_id' => 'user',
        'position_id' => 'position',
        'division_id' => 'division',
        'department_id' => 'department',
        'work_schedule_id' => 'workSchedule',
        'shift_id' => 'shift',
        'leave_type_id' => 'leaveType',
        'payroll_run_id' => 'payrollRun',
        'payroll_period_id' => 'payrollPeriod',
        'payroll_entry_id' => 'payrollEntry',
        'created_by_user_id' => 'creator',
        'reviewed_by_user_id' => 'reviewer',
        'applied_by_user_id' => 'applier',
        'uploaded_by_user_id' => 'uploader',
        'finalized_by_user_id' => 'finalizer',
        'generated_by_user_id' => 'generator',
        'published_by_user_id' => 'publisher',
        'updated_by_user_id' => 'updater',
        'acted_by_user_id' => 'actor',
        'delegator_user_id' => 'delegator',
        'delegate_user_id' => 'delegate',
        'daily_attendance_id' => 'dailyAttendance',
        'leave_request_id' => 'leaveRequest',
        'overtime_request_id' => 'overtimeRequest',
        'cto_request_id' => 'ctoRequest',
        'employee_profile_change_request_id' => 'changeRequest',
        'applicant_id' => 'applicant',
        'job_application_id' => 'application',
        'job_opening_id' => 'jobOpening',
        'recruitment_exam_id' => 'exam',
    ];

    /**
     * @param  list<string>  $fields
     */
    public function prepareCollection(mixed $resource, array $fields): void
    {
        $records = $resource instanceof AbstractPaginator ? $resource->getCollection() : $resource;

        if (! $records instanceof Collection || $records->isEmpty()) {
            return;
        }

        $records->loadMissing($this->relations($records->first(), $fields));

        foreach (['items', 'approvalActions', 'attempts'] as $relation) {
            $children = new Collection;

            foreach ($records as $record) {
                if (! $record->relationLoaded($relation) || ! $record->getRelation($relation) instanceof Collection) {
                    continue;
                }

                foreach ($record->getRelation($relation) as $child) {
                    if ($record instanceof PayrollEntry && $relation === 'items') {
                        $child->setRelation('payrollEntry', $record);
                    }

                    if ($record instanceof EmployeeProfileChangeRequest && $relation === 'items') {
                        $child->setRelation('changeRequest', $record);
                    }

                    $children->push($child);
                }
            }

            if ($children->isNotEmpty()) {
                $children->loadMissing($this->relations($children->first(), $children->first()->getFillable()));
            }
        }
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, string|null>
     */
    public function labels(Model $record, array $fields): array
    {
        $identifiers = array_values(array_filter($fields, static fn (string $field): bool => str_ends_with($field, '_id')));

        if ($identifiers === []) {
            return [];
        }

        $record->loadMissing($this->relations($record, $fields));
        $labels = [];

        foreach ($identifiers as $field) {
            $relation = self::RELATIONS[$field] ?? null;
            $related = $relation !== null && $record->relationLoaded($relation) ? $record->getRelation($relation) : null;
            $labels[$field] = $related instanceof Model ? $this->label($related) : null;
        }

        return $labels;
    }

    /**
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function relations(Model $record, array $fields): array
    {
        $relations = [];

        foreach (array_intersect_key(self::RELATIONS, array_flip($fields)) as $relation) {
            if (! method_exists($record, $relation)) {
                continue;
            }

            $relationship = $record->{$relation}();

            if (! $relationship instanceof BelongsTo) {
                continue;
            }

            $related = $relationship->getRelated();
            $columns = $this->columns($related);

            if ($columns === []) {
                continue;
            }

            $relations[] = $relation.':'.implode(',', $columns);

            if ($related instanceof PayrollRun) {
                $relations[] = $relation.'.payrollPeriod:'.implode(',', $this->columns(new PayrollPeriod));
            }

            if ($related instanceof PayrollEntry) {
                $relations[] = $relation.'.employee:'.implode(',', $this->columns(new Employee));
                $relations[] = $relation.'.payrollRun:'.implode(',', $this->columns(new PayrollRun));
                $relations[] = $relation.'.payrollRun.payrollPeriod:'.implode(',', $this->columns(new PayrollPeriod));
            }
        }

        return array_values(array_unique($relations));
    }

    /** @return list<string> */
    private function columns(Model $record): array
    {
        return match (true) {
            $record instanceof Employee => ['id', 'employee_number', 'first_name', 'middle_name', 'last_name', 'suffix'],
            $record instanceof User => ['id', 'name'],
            $record instanceof Applicant => ['id', 'first_name', 'middle_name', 'last_name'],
            $record instanceof PayrollPeriod => ['id', 'code', 'period_start', 'period_end'],
            $record instanceof PayrollRun => ['id', 'payroll_period_id', 'run_number'],
            $record instanceof PayrollEntry => ['id', 'employee_id', 'payroll_run_id'],
            $record instanceof DailyAttendance => ['id', 'attendance_date'],
            $record instanceof LeaveRequest => ['id', 'start_date', 'end_date'],
            $record instanceof CtoRequest, $record instanceof OvertimeRequest => ['id', 'start_at', 'end_at'],
            $record instanceof EmployeeProfileChangeRequest => ['id', 'created_at'],
            $record instanceof JobApplication => ['id', 'desired_position', 'created_at'],
            $record instanceof JobOpening, $record instanceof RecruitmentExam => ['id', 'title'],
            in_array('name', $record->getFillable(), true) && in_array('code', $record->getFillable(), true) => ['id', 'code', 'name'],
            default => [],
        };
    }

    private function label(Model $record): ?string
    {
        return match (true) {
            $record instanceof Employee => $this->join([$record->employee_number, $this->personName($record)]),
            $record instanceof Applicant => $this->personName($record),
            $record instanceof PayrollPeriod => $this->join([$record->code, $this->range($record->period_start, $record->period_end)]),
            $record instanceof PayrollRun => $this->join([
                $record->relationLoaded('payrollPeriod') && $record->payrollPeriod !== null ? $this->label($record->payrollPeriod) : null,
                $record->run_number !== null ? 'Run '.$record->run_number : null,
            ]),
            $record instanceof PayrollEntry => $this->join([
                $record->relationLoaded('employee') && $record->employee !== null ? $this->label($record->employee) : null,
                $record->relationLoaded('payrollRun') && $record->payrollRun !== null ? $this->label($record->payrollRun) : null,
            ]),
            $record instanceof DailyAttendance => $this->join(['Attendance', $this->date($record->attendance_date)]),
            $record instanceof LeaveRequest => $this->join(['Leave request', $this->range($record->start_date, $record->end_date)]),
            $record instanceof CtoRequest => $this->join(['CTO request', $this->range($record->start_at, $record->end_at, true)]),
            $record instanceof OvertimeRequest => $this->join(['Overtime request', $this->range($record->start_at, $record->end_at, true)]),
            $record instanceof EmployeeProfileChangeRequest => $this->join(['Profile change', $this->date($record->created_at)]),
            $record instanceof JobApplication => $this->join([$record->desired_position, $this->date($record->created_at)]),
            $record instanceof JobOpening, $record instanceof RecruitmentExam => $this->text($record->title),
            $record instanceof User => $this->text($record->name),
            default => $this->join([$this->text($record->name), $this->text($record->code)]),
        };
    }

    private function personName(Model $record): ?string
    {
        return $this->text(implode(' ', array_filter([
            $record->first_name, $record->middle_name, $record->last_name, $record->getAttributes()['suffix'] ?? null,
        ], static fn (mixed $part): bool => is_string($part) && trim($part) !== '')));
    }

    private function date(mixed $value, bool $withTime = false): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format($withTime ? 'Y-m-d H:i' : 'Y-m-d') : $this->text($value);
    }

    private function range(mixed $start, mixed $end, bool $withTime = false): ?string
    {
        return $this->join(array_unique([$this->date($start, $withTime), $this->date($end, $withTime)]), ' to ');
    }

    /** @param list<string|null> $parts */
    private function join(array $parts, string $separator = ' · '): ?string
    {
        return $this->text(implode($separator, array_filter($parts, static fn (?string $part): bool => $part !== null && $part !== '')));
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
