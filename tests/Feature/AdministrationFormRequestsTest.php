<?php

namespace Tests\Feature;

use App\Models\ApprovalDelegation;
use App\Models\CompanyProfile;
use App\Models\Department;
use App\Models\Division;
use App\Models\EmployeeScheduleAssignment;
use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\Shift;
use App\Models\TimeLog;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleDay;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class AdministrationFormRequestsTest extends FormRequestTestCase
{
    #[DataProvider('operations')]
    public function test_valid_input_returns_only_the_editable_fields(string $module, string $operation): void
    {
        $actor = $this->user(self::specifications()[$module]['permission']);
        $fixture = $this->fixture($module, $actor, $operation === 'update');
        $payload = $fixture['payload'] + [
            'id' => 999999,
            'created_by_user_id' => 999999,
            'created_at' => '2000-01-01 00:00:00',
            'updated_at' => '2000-01-01 00:00:00',
            'unexpected_admin_flag' => true,
        ];

        $validated = $this->validateRequest(
            self::requestClass($module, $operation),
            $payload,
            $actor,
            $fixture['bindings'],
        );

        $this->assertSame($fixture['payload'], $validated);
    }

    #[DataProvider('unauthorizedActors')]
    public function test_write_requires_an_active_user_with_the_policy_permission(
        string $module,
        string $operation,
        string $actorState,
    ): void {
        $actor = match ($actorState) {
            'guest' => null,
            'unprivileged' => $this->user(),
            'inactive' => $this->user(self::specifications()[$module]['permission']),
        };
        if ($actorState === 'inactive') {
            $actor->update(['is_active' => false]);
        }
        $fixture = $this->fixture($module, $actor, $operation === 'update');

        $this->assertUnauthorizedRequest(
            self::requestClass($module, $operation),
            $fixture['payload'],
            $actor,
            $fixture['bindings'],
        );
    }

    #[DataProvider('invalidUpdateBindings')]
    public function test_update_requires_the_expected_bound_model(string $module, string $bindingState): void
    {
        $actor = $this->user(self::specifications()[$module]['permission']);
        $fixture = $this->fixture($module, $actor, true);
        $routeKey = self::specifications()[$module]['route'];
        $bindings = match ($bindingState) {
            'missing' => [],
            'wrong_model' => [$routeKey => $actor],
            'scalar' => [$routeKey => $fixture['model']->id],
        };

        $this->assertUnauthorizedRequest(
            self::requestClass($module, 'update'),
            $fixture['payload'],
            $actor,
            $bindings,
        );
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_editable_input_is_rejected(
        string $module,
        string $operation,
        string $field,
        mixed $value,
        bool $remove,
    ): void {
        $actor = $this->user(self::specifications()[$module]['permission']);
        $fixture = $this->fixture($module, $actor, $operation === 'update');
        $payload = $fixture['payload'];
        if ($remove) {
            unset($payload[$field]);
        } else {
            $payload[$field] = $value;
        }

        $this->assertInvalidRequest(
            self::requestClass($module, $operation),
            $payload,
            $actor,
            $fixture['bindings'],
            [$field],
        );
    }

    #[DataProvider('malformedComplexInputs')]
    public function test_complex_input_types_produce_validation_errors_instead_of_exceptions(
        string $module,
        string $operation,
        string $field,
        mixed $value,
    ): void {
        $actor = $this->user(self::specifications()[$module]['permission']);
        $fixture = $this->fixture($module, $actor, $operation === 'update');
        $payload = array_replace($fixture['payload'], [$field => $value]);

        $this->assertInvalidRequest(
            self::requestClass($module, $operation),
            $payload,
            $actor,
            $fixture['bindings'],
            [$field],
        );
    }

    #[DataProvider('uniqueModules')]
    public function test_store_rejects_an_existing_unique_value(string $module, string $field): void
    {
        $actor = $this->user(self::specifications()[$module]['permission']);
        $fixture = $this->fixture($module, $actor, true);

        $this->assertInvalidRequest(
            self::requestClass($module, 'store'),
            $fixture['payload'],
            $actor,
            [],
            [$field],
        );
    }

    #[DataProvider('uniqueModules')]
    public function test_update_cannot_spoof_the_record_ignored_for_uniqueness(string $module, string $field): void
    {
        $actor = $this->user(self::specifications()[$module]['permission']);
        $conflict = $this->fixture($module, $actor, true);
        $alternate = $conflict['payload'];
        $alternate[$field] = match ($field) {
            'day_of_week' => 2,
            'name' => 'A different holiday',
            default => 'ALTERNATE',
        };
        $modelClass = self::specifications()[$module]['model'];
        $recordBeingUpdated = $modelClass::query()->create($alternate);
        $payload = $conflict['payload'] + ['id' => $conflict['model']->id];

        $this->assertInvalidRequest(
            self::requestClass($module, 'update'),
            $payload,
            $actor,
            [self::specifications()[$module]['route'] => $recordBeingUpdated],
            [$field],
        );
    }

    #[DataProvider('workingDayOperations')]
    public function test_work_schedule_day_working_days_require_an_active_shift(string $operation, string $shiftState): void
    {
        $actor = $this->user('schedules.manage');
        $fixture = $this->fixture('WorkScheduleDay', $actor, $operation === 'update');
        $payload = $fixture['payload'];
        if ($shiftState === 'missing') {
            unset($payload['shift_id']);
        } elseif ($shiftState === 'null') {
            $payload['shift_id'] = null;
        } else {
            Shift::query()->findOrFail($payload['shift_id'])->update(['is_active' => false]);
        }

        $this->assertInvalidRequest(
            self::requestClass('WorkScheduleDay', $operation),
            $payload,
            $actor,
            $fixture['bindings'],
            ['shift_id'],
        );
    }

    #[DataProvider('storeAndUpdate')]
    public function test_work_schedule_day_rest_days_allow_no_shift(string $operation): void
    {
        $actor = $this->user('schedules.manage');
        $fixture = $this->fixture('WorkScheduleDay', $actor, $operation === 'update');
        $payload = array_replace($fixture['payload'], ['is_working_day' => false, 'shift_id' => null]);

        $validated = $this->validateRequest(
            self::requestClass('WorkScheduleDay', $operation),
            $payload,
            $actor,
            $fixture['bindings'],
        );

        $this->assertSame($payload, $validated);
    }

    #[DataProvider('boundWorkingDayFlags')]
    public function test_work_schedule_day_update_uses_the_bound_working_flag_when_input_omits_it(bool $working): void
    {
        $actor = $this->user('schedules.manage');
        $fixture = $this->fixture('WorkScheduleDay', $actor, true);
        $fixture['model']->update(['is_working_day' => $working]);
        $payload = $fixture['payload'];
        unset($payload['is_working_day']);
        if (! $working) {
            unset($payload['shift_id']);
        }

        $validated = $this->validateRequest(
            self::requestClass('WorkScheduleDay', 'update'),
            $payload,
            $actor,
            $fixture['bindings'],
        );

        $this->assertSame($payload, $validated);
    }

    public function test_work_schedule_day_update_of_a_working_day_requires_shift_input_when_working_flag_is_omitted(): void
    {
        $actor = $this->user('schedules.manage');
        $fixture = $this->fixture('WorkScheduleDay', $actor, true);
        $payload = $fixture['payload'];
        unset($payload['is_working_day'], $payload['shift_id']);

        $this->assertInvalidRequest(
            self::requestClass('WorkScheduleDay', 'update'),
            $payload,
            $actor,
            $fixture['bindings'],
            ['shift_id'],
        );
    }

    #[DataProvider('storeAndUpdate')]
    public function test_employee_schedule_assignment_rejects_an_inactive_work_schedule(string $operation): void
    {
        $actor = $this->user('schedules.assign');
        $fixture = $this->fixture('EmployeeScheduleAssignment', $actor, $operation === 'update');
        WorkSchedule::query()->findOrFail($fixture['payload']['work_schedule_id'])->update(['is_active' => false]);

        $this->assertInvalidRequest(
            self::requestClass('EmployeeScheduleAssignment', $operation),
            $fixture['payload'],
            $actor,
            $fixture['bindings'],
            ['work_schedule_id'],
        );
    }

    #[DataProvider('storeAndUpdate')]
    public function test_employee_schedule_assignment_accepts_an_open_ended_period(string $operation): void
    {
        $actor = $this->user('schedules.assign');
        $fixture = $this->fixture('EmployeeScheduleAssignment', $actor, $operation === 'update');
        $payload = array_replace($fixture['payload'], ['effective_to' => null]);

        $validated = $this->validateRequest(
            self::requestClass('EmployeeScheduleAssignment', $operation),
            $payload,
            $actor,
            $fixture['bindings'],
        );

        $this->assertSame($payload, $validated);
    }

    #[DataProvider('storeAndUpdate')]
    public function test_approval_delegation_cannot_delegate_someone_elses_authority_without_employee_access(string $operation): void
    {
        $actor = $this->user('approval-delegations.manage');
        $fixture = $this->fixture('ApprovalDelegation', $actor, $operation === 'update');
        $anotherApprover = $this->user('leave.approve');
        $payload = array_replace($fixture['payload'], ['delegator_user_id' => $anotherApprover->id]);

        $this->assertInvalidRequest(
            self::requestClass('ApprovalDelegation', $operation),
            $payload,
            $actor,
            $fixture['bindings'],
            ['delegator_user_id'],
        );
    }

    public function test_approval_delegation_update_of_another_delegators_record_is_unauthorized(): void
    {
        $actor = $this->user('approval-delegations.manage');
        $owner = $this->user('approval-delegations.manage');
        $fixture = $this->fixture('ApprovalDelegation', $owner, true);

        $this->assertUnauthorizedRequest(
            self::requestClass('ApprovalDelegation', 'update'),
            $fixture['payload'],
            $actor,
            $fixture['bindings'],
        );
    }

    #[DataProvider('storeAndUpdate')]
    public function test_approval_delegation_employee_administrator_can_manage_another_delegators_record(string $operation): void
    {
        $actor = $this->user('approval-delegations.manage', 'employees.view');
        $owner = $this->user('leave.approve');
        $fixture = $this->fixture('ApprovalDelegation', $owner, $operation === 'update');

        $validated = $this->validateRequest(
            self::requestClass('ApprovalDelegation', $operation),
            $fixture['payload'],
            $actor,
            $fixture['bindings'],
        );

        $this->assertSame($fixture['payload'], $validated);
    }

    #[DataProvider('storeAndUpdate')]
    public function test_approval_delegation_rejects_self_delegation(string $operation): void
    {
        $actor = $this->user('approval-delegations.manage');
        $fixture = $this->fixture('ApprovalDelegation', $actor, $operation === 'update');
        $payload = array_replace($fixture['payload'], ['delegate_user_id' => $actor->id]);

        $this->assertInvalidRequest(
            self::requestClass('ApprovalDelegation', $operation),
            $payload,
            $actor,
            $fixture['bindings'],
            ['delegate_user_id'],
        );
    }

    #[DataProvider('nullableParents')]
    public function test_nullable_organization_parent_is_accepted(string $module, string $operation, string $field): void
    {
        $actor = $this->user('organization.manage');
        $fixture = $this->fixture($module, $actor, $operation === 'update');
        $payload = array_replace($fixture['payload'], [$field => null]);

        $validated = $this->validateRequest(
            self::requestClass($module, $operation),
            $payload,
            $actor,
            $fixture['bindings'],
        );

        $this->assertSame($payload, $validated);
    }

    #[DataProvider('storeAndUpdate')]
    public function test_work_schedule_day_same_weekday_is_allowed_for_a_different_schedule(string $operation): void
    {
        $actor = $this->user('schedules.manage');
        $fixture = $this->fixture('WorkScheduleDay', $actor, true);
        $otherSchedule = WorkSchedule::query()->create(['code' => 'OTHER', 'name' => 'Other schedule']);
        $payload = array_replace($fixture['payload'], ['work_schedule_id' => $otherSchedule->id]);

        $validated = $this->validateRequest(
            self::requestClass('WorkScheduleDay', $operation),
            $payload,
            $actor,
            $operation === 'update' ? $fixture['bindings'] : [],
        );

        $this->assertSame($payload, $validated);
    }

    #[DataProvider('storeAndUpdate')]
    public function test_holiday_same_name_is_allowed_on_a_different_date(string $operation): void
    {
        $actor = $this->user('holidays.manage');
        $fixture = $this->fixture('Holiday', $actor, true);
        $payload = array_replace($fixture['payload'], ['holiday_date' => '2026-10-16']);

        $validated = $this->validateRequest(
            self::requestClass('Holiday', $operation),
            $payload,
            $actor,
            $operation === 'update' ? $fixture['bindings'] : [],
        );

        $this->assertSame($payload, $validated);
    }

    #[DataProvider('unconstrainedStrings')]
    public function test_schema_supported_strings_are_accepted_without_invented_enums(
        string $module,
        string $operation,
        string $field,
        string $value,
    ): void {
        $actor = $this->user(self::specifications()[$module]['permission']);
        $fixture = $this->fixture($module, $actor, $operation === 'update');
        $payload = array_replace($fixture['payload'], [$field => $value]);

        $validated = $this->validateRequest(
            self::requestClass($module, $operation),
            $payload,
            $actor,
            $fixture['bindings'],
        );

        $this->assertSame($payload, $validated);
    }

    #[DataProvider('storeAndUpdate')]
    public function test_shift_overnight_time_window_is_accepted(string $operation): void
    {
        $actor = $this->user('schedules.manage');
        $fixture = $this->fixture('Shift', $actor, $operation === 'update');
        $payload = array_replace($fixture['payload'], [
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'is_overnight' => true,
        ]);

        $validated = $this->validateRequest(
            self::requestClass('Shift', $operation),
            $payload,
            $actor,
            $fixture['bindings'],
        );

        $this->assertSame($payload, $validated);
    }

    public static function operations(): array
    {
        $cases = [];
        foreach (self::specifications() as $module => $specification) {
            foreach ($specification['operations'] as $operation) {
                $cases["$module $operation"] = [$module, $operation];
            }
        }

        return $cases;
    }

    public static function unauthorizedActors(): array
    {
        $cases = [];
        foreach (self::operations() as $label => [$module, $operation]) {
            foreach (['guest', 'unprivileged', 'inactive'] as $state) {
                $cases["$label $state"] = [$module, $operation, $state];
            }
        }

        return $cases;
    }

    public static function invalidUpdateBindings(): array
    {
        $cases = [];
        foreach (array_keys(self::specifications()) as $module) {
            foreach (['missing', 'wrong_model', 'scalar'] as $state) {
                $cases["$module $state"] = [$module, $state];
            }
        }

        return $cases;
    }

    public static function uniqueModules(): array
    {
        return [
            'Division' => ['Division', 'code'],
            'Department' => ['Department', 'code'],
            'Position' => ['Position', 'code'],
            'Shift' => ['Shift', 'code'],
            'WorkSchedule' => ['WorkSchedule', 'code'],
            'WorkScheduleDay' => ['WorkScheduleDay', 'day_of_week'],
            'Holiday' => ['Holiday', 'name'],
            'LeaveType' => ['LeaveType', 'code'],
            'PayrollPeriod' => ['PayrollPeriod', 'code'],
        ];
    }

    public static function storeAndUpdate(): array
    {
        return ['store' => ['store'], 'update' => ['update']];
    }

    public static function nullableParents(): array
    {
        return [
            'Department store' => ['Department', 'store', 'division_id'],
            'Department update' => ['Department', 'update', 'division_id'],
            'Position store' => ['Position', 'store', 'department_id'],
            'Position update' => ['Position', 'update', 'department_id'],
        ];
    }

    public static function unconstrainedStrings(): array
    {
        $cases = [];
        foreach (['store', 'update'] as $operation) {
            $cases["Holiday $operation custom type"] = ['Holiday', $operation, 'holiday_type', 'regional_observed'];
            $cases["PayrollPeriod $operation custom frequency"] = ['PayrollPeriod', $operation, 'pay_frequency', 'custom_cycle'];
            $cases["PayrollPeriod $operation custom status"] = ['PayrollPeriod', $operation, 'status', 'custom_state'];
            $cases["TimeLog $operation custom source"] = ['TimeLog', $operation, 'source', 'custom_reader'];
            $cases["TimeLog $operation custom type"] = ['TimeLog', $operation, 'log_type', 'custom_tap'];
        }

        return $cases;
    }

    public static function workingDayOperations(): array
    {
        $cases = [];
        foreach (['store', 'update'] as $operation) {
            foreach (['missing', 'null', 'inactive'] as $state) {
                $cases["WorkScheduleDay $operation $state"] = [$operation, $state];
            }
        }

        return $cases;
    }

    public static function boundWorkingDayFlags(): array
    {
        return ['working day with active shift' => [true], 'rest day without shift input' => [false]];
    }

    public static function malformedComplexInputs(): array
    {
        $fields = [
            'Shift' => ['start_time', 'end_time'],
            'WorkScheduleDay' => ['work_schedule_id', 'day_of_week'],
            'Holiday' => ['name', 'holiday_date'],
        ];
        $cases = [];
        foreach ($fields as $module => $moduleFields) {
            foreach (['store', 'update'] as $operation) {
                foreach ($moduleFields as $field) {
                    $cases["$module $operation $field array"] = [$module, $operation, $field, ['invalid']];
                    $cases["$module $operation $field boolean"] = [$module, $operation, $field, true];
                }
            }
        }
        foreach (['store', 'update'] as $operation) {
            $cases["WorkScheduleDay $operation working flag array"] = [
                'WorkScheduleDay', $operation, 'is_working_day', ['invalid'],
            ];
        }

        return $cases;
    }

    public static function invalidInputs(): array
    {
        $scenarios = [
            'CompanyProfile' => [
                ['name', null, true], ['name', str_repeat('n', 151)],
                ['legal_name', str_repeat('n', 201)], ['email', 'invalid-email'],
                ['phone', str_repeat('n', 51)], ['country', null, true],
                ['country', str_repeat('n', 101)], ['postal_code', str_repeat('n', 21)],
            ],
            'Division' => [
                ['code', null, true], ['code', str_repeat('c', 51)],
                ['name', null, true], ['name', str_repeat('n', 151)],
                ['is_active', 'not-a-boolean'],
            ],
            'Department' => [
                ['code', null, true], ['code', str_repeat('c', 51)],
                ['name', null, true], ['name', str_repeat('n', 151)],
                ['division_id', 999999], ['division_id', 'invalid-id'],
                ['is_active', 'not-a-boolean'],
            ],
            'Position' => [
                ['code', null, true], ['code', str_repeat('c', 51)],
                ['name', null, true], ['name', str_repeat('n', 151)],
                ['department_id', 999999], ['department_id', 'invalid-id'],
                ['is_active', 'not-a-boolean'],
            ],
            'Shift' => [
                ['code', null, true], ['code', str_repeat('c', 51)],
                ['name', str_repeat('n', 101)], ['start_time', null, true],
                ['start_time', '25:00:00'], ['end_time', 'invalid-time'],
                ['end_time', '07:59:00'], ['break_minutes', -1],
                ['late_grace_minutes', 65536], ['undertime_grace_minutes', 1.5],
                ['is_overnight', 'not-a-boolean'], ['is_active', 'not-a-boolean'],
            ],
            'WorkSchedule' => [
                ['code', null, true], ['code', str_repeat('c', 51)],
                ['name', null, true], ['name', str_repeat('n', 151)],
                ['is_active', 'not-a-boolean'],
            ],
            'WorkScheduleDay' => [
                ['work_schedule_id', null, true], ['work_schedule_id', 999999],
                ['day_of_week', null, true], ['day_of_week', 0], ['day_of_week', 8],
                ['shift_id', 999999], ['is_working_day', 'not-a-boolean'],
            ],
            'EmployeeScheduleAssignment' => [
                ['employee_id', null, true], ['employee_id', 999999],
                ['work_schedule_id', null, true], ['work_schedule_id', 999999],
                ['effective_from', null, true], ['effective_from', 'invalid-date'],
                ['effective_from', ['invalid-date']],
                ['effective_to', 'invalid-date'], ['effective_to', '2026-09-30'],
            ],
            'Holiday' => [
                ['name', null, true], ['name', str_repeat('n', 151)],
                ['holiday_date', null, true], ['holiday_date', 'invalid-date'],
                ['holiday_type', null, true], ['holiday_type', str_repeat('t', 51)],
                ['is_working_day', 'not-a-boolean'], ['is_paid', 'not-a-boolean'],
                ['is_active', 'not-a-boolean'],
            ],
            'LeaveType' => [
                ['code', null, true], ['code', str_repeat('c', 51)],
                ['name', null, true], ['name', str_repeat('n', 151)],
                ['is_paid', 'not-a-boolean'], ['requires_balance', 'not-a-boolean'],
                ['allows_partial_day', 'not-a-boolean'], ['requires_document', 'not-a-boolean'],
                ['is_active', 'not-a-boolean'],
            ],
            'ApprovalDelegation' => [
                ['delegator_user_id', null, true], ['delegator_user_id', 999999],
                ['delegate_user_id', null, true], ['delegate_user_id', 999999],
                ['starts_at', null, true], ['starts_at', 'invalid-date'],
                ['starts_at', ['invalid-date']],
                ['ends_at', null, true], ['ends_at', '2026-09-30 09:00:00'],
                ['is_active', 'not-a-boolean'],
            ],
            'PayrollPeriod' => [
                ['code', null, true], ['code', str_repeat('c', 51)],
                ['period_start', null, true], ['period_start', 'invalid-date'],
                ['period_start', ['invalid-date']],
                ['period_end', null, true], ['period_end', '2026-09-30'],
                ['pay_date', null, true], ['pay_date', 'invalid-date'],
                ['pay_frequency', null, true], ['pay_frequency', str_repeat('f', 31)],
                ['status', str_repeat('s', 31)],
            ],
            'TimeLog' => [
                ['employee_id', null, true], ['employee_id', 999999],
                ['logged_at', null, true], ['logged_at', 'invalid-date'],
                ['log_type', str_repeat('t', 21)], ['source', str_repeat('s', 31)],
                ['device_code', str_repeat('d', 101)], ['external_reference', str_repeat('r', 151)],
                ['is_valid', 'not-a-boolean'],
            ],
        ];
        $cases = [];
        foreach ($scenarios as $module => $fields) {
            foreach (self::specifications()[$module]['operations'] as $operation) {
                foreach ($fields as $index => $scenario) {
                    [$field, $value] = $scenario;
                    $cases["$module $operation $field case $index"] = [
                        $module, $operation, $field, $value, $scenario[2] ?? false,
                    ];
                }
            }
        }

        return $cases;
    }

    private static function requestClass(string $module, string $operation): string
    {
        return 'App\\Http\\Requests\\'.($operation === 'store' ? 'Store' : 'Update').$module.'Request';
    }

    private static function specifications(): array
    {
        return [
            'CompanyProfile' => ['model' => CompanyProfile::class, 'route' => 'companyProfile', 'permission' => 'organization.manage', 'operations' => ['update']],
            'Division' => ['model' => Division::class, 'route' => 'division', 'permission' => 'organization.manage', 'operations' => ['store', 'update']],
            'Department' => ['model' => Department::class, 'route' => 'department', 'permission' => 'organization.manage', 'operations' => ['store', 'update']],
            'Position' => ['model' => Position::class, 'route' => 'position', 'permission' => 'organization.manage', 'operations' => ['store', 'update']],
            'Shift' => ['model' => Shift::class, 'route' => 'shift', 'permission' => 'schedules.manage', 'operations' => ['store', 'update']],
            'WorkSchedule' => ['model' => WorkSchedule::class, 'route' => 'workSchedule', 'permission' => 'schedules.manage', 'operations' => ['store', 'update']],
            'WorkScheduleDay' => ['model' => WorkScheduleDay::class, 'route' => 'workScheduleDay', 'permission' => 'schedules.manage', 'operations' => ['store', 'update']],
            'EmployeeScheduleAssignment' => ['model' => EmployeeScheduleAssignment::class, 'route' => 'employeeScheduleAssignment', 'permission' => 'schedules.assign', 'operations' => ['store', 'update']],
            'Holiday' => ['model' => Holiday::class, 'route' => 'holiday', 'permission' => 'holidays.manage', 'operations' => ['store', 'update']],
            'LeaveType' => ['model' => LeaveType::class, 'route' => 'leaveType', 'permission' => 'leave.manage-types', 'operations' => ['store', 'update']],
            'ApprovalDelegation' => ['model' => ApprovalDelegation::class, 'route' => 'approvalDelegation', 'permission' => 'approval-delegations.manage', 'operations' => ['store', 'update']],
            'PayrollPeriod' => ['model' => PayrollPeriod::class, 'route' => 'payrollPeriod', 'permission' => 'payroll.process', 'operations' => ['store', 'update']],
            'TimeLog' => ['model' => TimeLog::class, 'route' => 'timeLog', 'permission' => 'attendance.manage', 'operations' => ['store', 'update']],
        ];
    }

    private function fixture(string $module, ?User $actor, bool $persist): array
    {
        $payload = match ($module) {
            'CompanyProfile' => [
                'name' => 'Sample HRIS Company', 'legal_name' => 'Sample HRIS Company Inc.',
                'registration_number' => 'REG-001', 'tax_identification_number' => 'TIN-001',
                'email' => 'office@example.test', 'phone' => '+63 123 456 789',
                'website' => 'https://example.test', 'address_line_1' => '123 Example Street',
                'address_line_2' => null, 'city' => 'Manila', 'province' => 'Metro Manila',
                'postal_code' => '1000', 'country' => 'Philippines', 'logo_path' => 'logos/company.png',
            ],
            'Division', 'WorkSchedule' => [
                'code' => 'MAIN', 'name' => 'Main unit', 'description' => null, 'is_active' => true,
            ],
            'Department' => [
                'division_id' => Division::query()->create(['code' => 'PARENT-DIV', 'name' => 'Parent division'])->id,
                'code' => 'DEPT', 'name' => 'Department', 'description' => null, 'is_active' => true,
            ],
            'Position' => [
                'department_id' => Department::query()->create(['code' => 'PARENT-DEPT', 'name' => 'Parent department'])->id,
                'code' => 'POSITION', 'name' => 'Position', 'description' => null, 'is_active' => true,
            ],
            'Shift' => [
                'code' => 'DAY', 'name' => 'Day shift', 'start_time' => '08:00:00', 'end_time' => '17:00:00',
                'break_minutes' => 60, 'late_grace_minutes' => 5, 'undertime_grace_minutes' => 5,
                'is_overnight' => false, 'is_active' => true, 'remarks' => null,
            ],
            'WorkScheduleDay' => [
                'work_schedule_id' => $this->workSchedule()->id, 'day_of_week' => 1,
                'is_working_day' => true, 'shift_id' => $this->shift()->id,
            ],
            'EmployeeScheduleAssignment' => [
                'employee_id' => $this->employee()->id, 'work_schedule_id' => $this->workSchedule()->id,
                'effective_from' => '2026-10-01', 'effective_to' => '2026-10-31', 'remarks' => null,
            ],
            'Holiday' => [
                'name' => 'Company holiday', 'holiday_date' => '2026-10-15', 'holiday_type' => 'company',
                'description' => null, 'is_working_day' => false, 'is_paid' => true, 'is_active' => true,
            ],
            'LeaveType' => [
                'code' => 'CUSTOM', 'name' => 'Custom leave', 'description' => null,
                'is_paid' => true, 'requires_balance' => true, 'allows_partial_day' => true,
                'requires_document' => false, 'is_active' => true,
            ],
            'ApprovalDelegation' => [
                'delegator_user_id' => ($actor ?? $this->user('approval-delegations.manage'))->id,
                'delegate_user_id' => $this->user('leave.approve')->id,
                'starts_at' => '2026-10-01 09:00:00', 'ends_at' => '2026-10-31 18:00:00',
                'reason' => null, 'is_active' => true,
            ],
            'PayrollPeriod' => [
                'code' => '2026-OCT-FIRST', 'period_start' => '2026-10-01', 'period_end' => '2026-10-15',
                'pay_date' => '2026-10-20', 'pay_frequency' => 'semi_monthly', 'status' => 'open',
            ],
            'TimeLog' => [
                'employee_id' => $this->employee()->id, 'logged_at' => '2026-10-01 08:00:00',
                'log_type' => 'tap', 'source' => 'manual', 'device_code' => null,
                'external_reference' => 'ATT-001', 'reason' => null, 'remarks' => null, 'is_valid' => true,
            ],
        };
        $specification = self::specifications()[$module];
        $modelClass = $specification['model'];
        $model = $persist ? $modelClass::query()->create($payload) : null;

        return [
            'payload' => $payload,
            'model' => $model,
            'bindings' => $model instanceof Model ? [$specification['route'] => $model] : [],
        ];
    }

    private function workSchedule(): WorkSchedule
    {
        return WorkSchedule::query()->create(['code' => 'STANDARD', 'name' => 'Standard schedule', 'is_active' => true]);
    }

    private function shift(): Shift
    {
        return Shift::query()->create([
            'code' => 'STANDARD-DAY', 'name' => 'Standard day shift',
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'is_active' => true,
        ]);
    }
}
