<?php

namespace App\Services\Report;

use App\Models\CtoBalance;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\PayrollEntry;
use App\Models\User;
use App\Services\Workspace\WorkspaceReadScope;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ReportService
{
    public const MAX_EXPORT_ROWS = 10000;

    public function __construct(private readonly WorkspaceReadScope $visibility) {}

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $identity = ['id' => ['Record ID', 'integer'], 'employee_number' => ['Employee number', 'text'], 'employee_name' => ['Employee', 'text']];
        $definitions = [
            'employees' => $this->makeDefinition('Employee roster', 'Current employment and organization details.', Employee::class, ['employees.view', 'employees.view-team'], 'snapshot', null, ['active', 'inactive'], false, $identity + [
                'position' => ['Position', 'text'], 'department' => ['Department', 'text'], 'division' => ['Division', 'text'], 'hire_date' => ['Hire date', 'date'],
                'employment_type' => ['Employment type', 'text'], 'employment_status' => ['Employment status', 'text'], 'is_active' => ['Active', 'boolean'],
            ]),
            'attendance' => $this->makeDefinition('Daily attendance', 'Processed attendance records; missing records are not counted as absences.', DailyAttendance::class, ['attendance.view'], 'attendance', 'Attendance date', ['present', 'absent', 'incomplete', 'rest_day', 'holiday'], false, $identity + [
                'attendance_date' => ['Date', 'date'], 'status' => ['Status', 'text'], 'scheduled_in' => ['Scheduled in', 'datetime'], 'scheduled_out' => ['Scheduled out', 'datetime'],
                'actual_in' => ['Actual in', 'datetime'], 'actual_out' => ['Actual out', 'datetime'], 'worked_minutes' => ['Worked minutes', 'integer'],
                'late_minutes' => ['Late minutes', 'integer'], 'undertime_minutes' => ['Undertime minutes', 'integer'], 'overtime_minutes' => ['Overtime minutes', 'integer'],
                'is_rest_day' => ['Rest day', 'boolean'], 'is_holiday' => ['Holiday', 'boolean'],
            ]),
            'leave-balances' => $this->makeDefinition('Leave balances', 'Current recorded credits for the selected leave year.', LeaveBalance::class, ['leave.view'], 'snapshot', null, [], true, $identity + [
                'leave_type' => ['Leave type', 'text'], 'year' => ['Year', 'integer'], 'balance' => ['Balance (days)', 'decimal'],
            ]),
            'cto-balances' => $this->makeDefinition('CTO balances', 'Current recorded CTO credits in minutes.', CtoBalance::class, ['cto.view'], 'snapshot', null, [], false, $identity + [
                'balance_minutes' => ['Balance (minutes)', 'integer'],
            ]),
            'payroll' => $this->makeDefinition('Payroll register', 'Payroll entries in overlapping coverage periods. Totals are grouped by currency.', PayrollEntry::class, ['payroll.view'], 'payroll', 'Payroll coverage overlap', ['draft', 'processing', 'completed', 'finalized'], false, $identity + [
                'period_code' => ['Period', 'text'], 'period_start' => ['Coverage start', 'date'], 'period_end' => ['Coverage end', 'date'], 'pay_date' => ['Pay date', 'date'],
                'payroll_run_id' => ['Run ID', 'integer'], 'run_number' => ['Run number', 'integer'], 'status' => ['Run status', 'text'], 'entry_status' => ['Entry status', 'text'],
                'currency' => ['Currency', 'text'], 'basic_salary' => ['Basic salary', 'decimal'], 'gross_pay' => ['Gross pay', 'decimal'],
                'total_deductions' => ['Deductions', 'decimal'], 'net_pay' => ['Net pay', 'decimal'],
            ]),
        ];

        foreach ($this->visibility->workflows() as $key => $workflow) {
            $kind = $workflow['date_kind'];
            $columns = $identity + ['status' => ['Status', 'text']];
            $columns += match ($kind) {
                'date' => ['start_date' => ['Start date', 'date'], 'end_date' => ['End date', 'date'], 'requested_units' => ['Requested days', 'decimal'], 'approved_units' => ['Approved days', 'decimal']],
                'time' => ['start_at' => ['Start', 'datetime'], 'end_at' => ['End', 'datetime']],
                'attendance' => ['attendance_date' => ['Attendance date', 'date']],
                default => [],
            };

            if (in_array($key, ['cto', 'overtime'], true)) {
                $columns += ['requested_minutes' => ['Requested minutes', 'integer'], 'approved_minutes' => ['Approved minutes', 'integer']];
            }

            $columns += ['submitted_at' => ['Submitted', 'datetime'], 'reviewed_at' => ['Reviewed', 'datetime']];
            $definitions[$key] = $this->makeDefinition($workflow['label'].' requests', 'Request register; requested and approved amounts cover the full request.', $workflow['model'], [$workflow['permission'].'.view'], 'workflow', match ($kind) {
                'date', 'time' => 'Request dates overlap', 'attendance' => 'Attendance date', default => 'Submission date',
            }, $key === 'profile_changes' ? ['pending', 'approved', 'rejected', 'cancelled', 'applied'] : ['pending', 'approved', 'rejected', 'cancelled'], false, $columns) + ['workflow' => $workflow];
        }

        foreach ($definitions as $key => &$definition) {
            $definition['key'] = $key;
        }
        unset($definition);

        return $definitions;
    }

    /** @return array<string, mixed> */
    public function definition(string $report): array
    {
        $definitions = $this->definitions();
        abort_unless(isset($definitions[$report]), 404);

        return $definitions[$report];
    }

    /** @return array<string, mixed> */
    public function catalog(User $user): array
    {
        Gate::forUser($user)->authorize('viewReports');
        $context = $this->visibility->context($user, ['scope' => 'visible']);
        $reports = [];

        foreach ($this->definitions() as $key => $definition) {
            if (Gate::forUser($user)->allows('viewReport', [$key])) {
                $reports[] = $this->descriptor($definition);
            }
        }

        return [
            'reports' => $reports, 'available_scopes' => $context['available_scopes'],
            'default_filters' => ['scope' => 'visible', 'start' => $context['range']['start'], 'end' => $context['range']['end'], 'year' => (int) substr($context['today'], 0, 4)],
            'export' => ['format' => 'csv', 'max_rows' => self::MAX_EXPORT_ROWS],
        ];
    }

    /** @return array<string, mixed> */
    public function preview(User $user, string $report, array $filters = []): array
    {
        Gate::forUser($user)->authorize('viewReport', [$report]);
        $definition = $this->definition($report);
        $context = $this->context($user, $definition, $filters);
        $query = $this->query($user, $definition, $context);
        $page = (clone $query)->orderBy('id')->paginate((int) ($filters['per_page'] ?? 20), ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'report' => $this->descriptor($definition), 'filters' => $context['filters'], 'available_scopes' => $context['available_scopes'],
            'generated_at' => CarbonImmutable::now(config('app.timezone'))->toAtomString(),
            'summary' => $this->summary($query, $definition, $page->total()),
            'rows' => $page->getCollection()->map(fn (Model $record): array => $this->row($record, $definition))->all(),
            'pagination' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'export' => ['format' => 'csv', 'max_rows' => self::MAX_EXPORT_ROWS, 'available' => $page->total() <= self::MAX_EXPORT_ROWS],
        ];
    }

    /** @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public function descriptor(array $definition): array
    {
        return array_intersect_key($definition, array_flip(['key', 'label', 'description', 'kind', 'date_basis', 'statuses', 'year_filter', 'columns'])) + ['can_export' => true];
    }

    /** @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public function context(User $user, array $definition, array $filters): array
    {
        $context = $this->visibility->context($user, $filters + ['scope' => 'visible']);
        $context['filters'] = [
            'scope' => $context['scope'], 'employee_id' => isset($filters['employee_id']) ? (int) $filters['employee_id'] : null,
            'start' => $definition['date_basis'] !== null ? $context['range']['start'] : null,
            'end' => $definition['date_basis'] !== null ? $context['range']['end'] : null,
            'status' => $filters['status'] ?? null,
            'year' => $definition['year_filter'] ? (int) ($filters['year'] ?? substr($context['today'], 0, 4)) : null,
            'payroll_run_id' => isset($filters['payroll_run_id']) ? (int) $filters['payroll_run_id'] : null,
        ];

        return $context;
    }

    /** @param array<string, mixed> $definition
     * @param  array<string, mixed>  $context
     */
    public function query(User $user, array $definition, array $context): Builder
    {
        $key = $definition['key'];
        $scope = $context['scope'];
        $filters = $context['filters'];
        $query = match ($key) {
            'employees' => $this->visibility->employees($user, $scope)->select(['id', 'employee_number', 'first_name', 'last_name', 'position_id', 'hire_date', 'employment_type', 'employment_status', 'is_active'])->with(['position:id,department_id,name', 'position.department:id,division_id,name', 'position.department.division:id,name']),
            'payroll' => $this->visibility->records($user, PayrollEntry::query(), $scope, true, false)->select(['id', 'employee_id', 'payroll_run_id', 'currency', 'basic_salary', 'gross_pay', 'total_deductions', 'net_pay', 'status'])->with(['payrollRun:id,payroll_period_id,run_number,status', 'payrollRun.payrollPeriod:id,code,period_start,period_end,pay_date']),
            default => isset($definition['workflow']) ? $this->visibility->workflowQuery($user, $definition['workflow'], $scope) : $this->visibility->records($user, $definition['model']::query(), $scope, $user->hasPermission('employees.view')),
        };

        if (! in_array($key, ['employees', 'payroll'], true)) {
            $fields = ['id', 'employee_id'];
            foreach ($definition['columns'] as $column) {
                if (! in_array($column['key'], ['employee_number', 'employee_name', 'leave_type'], true)) {
                    $fields[] = $column['key'] === 'submitted_at' ? 'created_at' : $column['key'];
                }
            }
            if ($key === 'leave-balances') {
                $fields[] = 'leave_type_id';
            }
            $query->select(array_values(array_unique($fields)));
        }

        if ($key !== 'employees') {
            $query->with('employee:id,employee_number,first_name,last_name');
        }
        if ($key === 'leave-balances') {
            $query->with('leaveType:id,name')->where('year', $filters['year']);
        }

        if ($definition['kind'] === 'payroll') {
            $query->whereHas('payrollRun.payrollPeriod', fn (Builder $period): Builder => $period->whereDate('period_start', '<=', $context['range']['end'])->whereDate('period_end', '>=', $context['range']['start']));
        } elseif ($definition['date_basis'] !== null) {
            $query = $this->visibility->inRange($query, $definition['workflow']['date_kind'] ?? 'attendance', $context['range']);
        }

        if ($filters['employee_id'] !== null) {
            $query->where($key === 'employees' ? 'id' : 'employee_id', $filters['employee_id']);
        }
        if ($filters['payroll_run_id'] !== null) {
            $query->where('payroll_run_id', $filters['payroll_run_id']);
        }
        if ($filters['status'] !== null) {
            if ($key === 'employees') {
                $query->where('is_active', $filters['status'] === 'active');
            } elseif ($key === 'payroll') {
                $query->whereHas('payrollRun', fn (Builder $run): Builder => $run->where('status', $filters['status']));
            } else {
                $query->where('status', $filters['status']);
            }
        }

        return $query;
    }

    /** @param array<string, mixed> $definition
     * @return array<string, int|string|bool|null>
     */
    public function row(Model $record, array $definition): array
    {
        $employee = $record instanceof Employee ? $record : $record->employee;
        $values = ['id' => $record->id, 'employee_number' => $employee?->employee_number, 'employee_name' => $employee === null ? null : trim($employee->first_name.' '.$employee->last_name)];

        if ($record instanceof Employee) {
            $values += ['position' => $record->position?->name, 'department' => $record->position?->department?->name, 'division' => $record->position?->department?->division?->name];
        } elseif ($record instanceof LeaveBalance) {
            $values['leave_type'] = $record->leaveType?->name;
        } elseif ($record instanceof PayrollEntry) {
            $period = $record->payrollRun?->payrollPeriod;
            $values += ['period_code' => $period?->code, 'period_start' => $period?->period_start?->toDateString(), 'period_end' => $period?->period_end?->toDateString(), 'pay_date' => $period?->pay_date?->toDateString(), 'run_number' => $record->payrollRun?->run_number, 'status' => $record->payrollRun?->status, 'entry_status' => $record->status];
        }

        $row = [];
        foreach ($definition['columns'] as $column) {
            $key = $column['key'];
            if (array_key_exists($key, $values)) {
                $row[$key] = $values[$key];

                continue;
            }
            $value = $record->getAttribute($key === 'submitted_at' ? 'created_at' : $key);
            $row[$key] = match ($column['type']) {
                'date' => $value?->toDateString(), 'datetime' => $value?->format('Y-m-d H:i:s'),
                'integer' => $value === null ? null : (int) $value, 'boolean' => (bool) $value,
                default => $value === null ? null : (string) $value,
            };
        }

        return $row;
    }

    /** @param array<string, mixed> $definition
     * @return array{records:int, metrics:list<array<string, mixed>>}
     */
    private function summary(Builder $query, array $definition, int $total): array
    {
        $metrics = [['label' => 'Records', 'value' => $total, 'type' => 'integer']];
        if (in_array($definition['kind'], ['attendance', 'workflow'], true)) {
            $groups = (clone $query)->toBase()->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->orderBy('status')->get();
            $statuses = [];
            foreach ($groups as $group) {
                $status = $group->status === null || $group->status === '' ? 'unknown' : $group->status;
                $statuses[$status] = ($statuses[$status] ?? 0) + (int) $group->total;
            }
            foreach ($statuses as $status => $count) {
                $metrics[] = ['label' => Str::headline($status), 'value' => $count, 'type' => 'integer'];
            }
        }
        if ($definition['key'] === 'employees') {
            $metrics[] = ['label' => 'Active employees', 'value' => (clone $query)->where('is_active', true)->count(), 'type' => 'integer'];
        }
        if ($definition['key'] === 'attendance' || $definition['key'] === 'cto-balances') {
            $fields = $definition['key'] === 'attendance' ? ['worked_minutes', 'late_minutes', 'undertime_minutes', 'overtime_minutes'] : ['balance_minutes'];
            $totals = (clone $query)->toBase()->select([])->selectRaw(implode(', ', array_map(fn (string $field): string => 'COALESCE(SUM('.$field.'), 0) AS '.$field, $fields)))->first();
            foreach ($fields as $field) {
                $metrics[] = ['label' => Str::headline($field), 'value' => (int) $totals->{$field}, 'type' => 'integer'];
            }
        }
        if ($definition['key'] === 'payroll') {
            $currencies = [];
            foreach ((clone $query)->setEagerLoads([])->select(['id', 'currency', 'gross_pay', 'total_deductions', 'net_pay'])->lazyById(500) as $entry) {
                foreach (['gross_pay', 'total_deductions', 'net_pay'] as $field) {
                    $currencies[$entry->currency][$field] = ($currencies[$entry->currency][$field] ?? BigDecimal::of('0.00'))->plus($entry->{$field});
                }
            }
            ksort($currencies);
            foreach ($currencies as $currency => $totals) {
                foreach ($totals as $field => $value) {
                    $metrics[] = ['label' => Str::headline($field).' ('.$currency.')', 'value' => (string) $value->toScale(2), 'type' => 'decimal'];
                }
            }
        }

        return ['records' => $total, 'metrics' => $metrics];
    }

    /** @param class-string<Model> $model
     * @param  list<string>  $permissions
     * @param  list<string>  $statuses
     * @param  array<string, array{string, string}>  $columns
     * @return array<string, mixed>
     */
    private function makeDefinition(string $label, string $description, string $model, array $permissions, string $kind, ?string $dateBasis, array $statuses, bool $yearFilter, array $columns): array
    {
        return [
            'label' => $label, 'description' => $description, 'model' => $model, 'permissions' => $permissions, 'kind' => $kind,
            'date_basis' => $dateBasis, 'statuses' => $statuses, 'year_filter' => $yearFilter,
            'columns' => array_map(fn (string $key, array $column): array => ['key' => $key, 'label' => $column[0], 'type' => $column[1]], array_keys($columns), array_values($columns)),
        ];
    }
}
