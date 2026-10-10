<?php

namespace Tests\Feature;

use App\Http\Requests\ApproveCtoRequest;
use App\Http\Requests\ApproveLeaveRequest;
use App\Http\Requests\ApproveOvertimeRequest;
use App\Http\Requests\StoreAttendanceCorrectionRequest;
use App\Http\Requests\StoreCtoRequest;
use App\Http\Requests\StoreEmployeeProfileChangeRequest;
use App\Http\Requests\StoreLeaveRequest;
use App\Http\Requests\StoreOvertimeRequest;
use App\Http\Requests\StoreSystemSettingRequest;
use App\Http\Requests\UpdateSystemSettingRequest;
use App\Models\Announcement;
use App\Models\CtoRequest;
use App\Models\DailyAttendance;
use App\Models\EmployeeProfileChangeRequest;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\SystemSetting;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class ExistingPhaseSevenFormRequestsTest extends FormRequestTestCase
{
    #[DataProvider('validProfileChanges')]
    public function test_accepts_only_valid_editable_profile_values(array $changes): void
    {
        $caller = $this->user('profile-changes.create');
        $payload = ['changes' => $changes];

        $validated = $this->validateRequest(StoreEmployeeProfileChangeRequest::class, $payload, $caller);

        $this->assertValidatedPayload($payload, $validated);
    }

    #[DataProvider('invalidProfileChanges')]
    public function test_rejects_profile_values_that_cannot_be_applied_to_employee_fields(array $changes, array $fields): void
    {
        $caller = $this->user('profile-changes.create');

        $this->assertInvalidRequest(StoreEmployeeProfileChangeRequest::class, ['changes' => $changes], $caller, [], $fields);
    }

    #[DataProvider('emptyActions')]
    public function test_authorized_action_requires_no_client_control_fields(string $module, string $class, string $key, string $permission): void
    {
        [$caller, $bindings] = $this->emptyActionFixture($module, $class, $key, $permission);

        $validated = $this->validateRequest($class, [
            'status' => 'forged_status',
            'created_by_user_id' => 987654321,
            'applied_by_user_id' => 987654321,
            'published_at' => '1900-01-01 00:00:00',
            'is_active' => true,
        ], $caller, $bindings);

        $this->assertSame([], $validated);
    }

    #[DataProvider('emptyActionAuthorization')]
    public function test_empty_action_requires_the_correct_caller_and_model(string $module, string $class, string $key, string $permission, string $scenario): void
    {
        [$caller, $bindings] = $this->emptyActionFixture($module, $class, $key, $permission);

        switch ($scenario) {
            case 'no permission':
                $caller = $this->user();
                break;
            case 'inactive caller':
                $caller->update(['is_active' => false]);
                break;
            case 'no caller':
                $caller = null;
                break;
            case 'missing model':
                $bindings = [];
                break;
            case 'scalar model':
                $bindings = [$key => $bindings[$key]->getKey()];
                break;
            case 'wrong model':
                $bindings = [$key => new SystemSetting];
                break;
            case 'wrong route key':
                $bindings = ['record' => $bindings[$key]];
                break;
        }

        $this->assertUnauthorizedRequest($class, [], $caller, $bindings);
    }

    #[DataProvider('validSettingIntegers')]
    public function test_accepts_integer_settings_the_service_can_decode(string $operation, mixed $value): void
    {
        [$class, $payload, $caller, $bindings] = $this->settingFixture($operation, $value);

        $validated = $this->validateRequest($class, $payload, $caller, $bindings);

        $this->assertSame($value, $validated['value']);
    }

    #[DataProvider('invalidSettingIntegers')]
    public function test_rejects_integer_settings_the_service_cannot_decode(string $operation, mixed $value): void
    {
        [$class, $payload, $caller, $bindings] = $this->settingFixture($operation, $value);

        $this->assertInvalidRequest($class, $payload, $caller, $bindings, ['value']);
    }

    #[DataProvider('validLeaveUnits')]
    public function test_accepts_positive_leave_units_within_the_database_limit(string $operation, int|string $value): void
    {
        [$class, $payload, $caller, $bindings, $field] = $this->leaveFixture($operation);
        $payload[$field] = $value;

        $validated = $this->validateRequest($class, $payload, $caller, $bindings);

        $this->assertSame($value, $validated[$field]);
    }

    #[DataProvider('invalidLeaveUnits')]
    public function test_rejects_zero_malformed_or_overflowing_leave_units(string $operation, mixed $value): void
    {
        [$class, $payload, $caller, $bindings, $field] = $this->leaveFixture($operation);
        $payload[$field] = $value;

        $this->assertInvalidRequest($class, $payload, $caller, $bindings, [$field]);
    }

    #[DataProvider('leaveModule')]
    public function test_leave_approval_cannot_exceed_requested_units(string $module): void
    {
        [$class, $payload, $caller, $bindings] = $this->leaveFixture('approve');
        $bindings['leaveRequest']->update(['requested_units' => '1.25']);
        $payload['approved_units'] = '1.26';

        $this->assertInvalidRequest($class, $payload, $caller, $bindings, ['approved_units']);
    }

    #[DataProvider('crossYearLeave')]
    public function test_calendar_year_restriction_only_applies_to_balance_managed_leave(bool $requiresBalance): void
    {
        [$class, $payload, $caller] = $this->leaveFixture('store', $requiresBalance);
        $payload['start_date'] = '2026-12-31';
        $payload['end_date'] = '2027-01-01';

        if ($requiresBalance) {
            $this->assertInvalidRequest($class, $payload, $caller, [], ['end_date']);

            return;
        }

        $validated = $this->validateRequest($class, $payload, $caller);

        $this->assertSame('2027-01-01', $validated['end_date']);
    }

    #[DataProvider('invalidLeaveDates')]
    public function test_invalid_leave_dates_fail_before_calendar_year_comparison(string $field, mixed $value): void
    {
        [$class, $payload, $caller] = $this->leaveFixture('store');
        $payload[$field] = $value;

        $this->assertInvalidRequest($class, $payload, $caller, [], [$field]);
    }

    #[DataProvider('validMinuteIntervals')]
    public function test_accepts_at_least_one_complete_requested_minute(string $module, string $endAt): void
    {
        [$class, $payload, $caller] = $this->minuteFixture($module, 'store');
        $payload['end_at'] = $endAt;
        $payload['requested_minutes'] = 999999;

        $validated = $this->validateRequest($class, $payload, $caller);

        $this->assertSame($endAt, $validated['end_at']);
        $this->assertArrayNotHasKey('requested_minutes', $validated);
    }

    #[DataProvider('invalidMinuteIntervals')]
    public function test_rejects_subminute_reversed_or_invalid_intervals(string $module, string $field, mixed $value): void
    {
        [$class, $payload, $caller] = $this->minuteFixture($module, 'store');
        $payload[$field] = $value;

        $this->assertInvalidRequest($class, $payload, $caller, [], [$field]);
    }

    #[DataProvider('validMinuteApprovals')]
    public function test_accepts_approved_minutes_up_to_the_requested_limit(string $module, int $minutes): void
    {
        [$class, $payload, $caller, $bindings] = $this->minuteFixture($module, 'approve');
        $payload['approved_minutes'] = $minutes;

        $validated = $this->validateRequest($class, $payload, $caller, $bindings);

        $this->assertSame($minutes, $validated['approved_minutes']);
    }

    #[DataProvider('invalidMinuteApprovals')]
    public function test_rejects_approved_minutes_outside_the_requested_limit(string $module, mixed $minutes): void
    {
        [$class, $payload, $caller, $bindings] = $this->minuteFixture($module, 'approve');
        $payload['approved_minutes'] = $minutes;

        $this->assertInvalidRequest($class, $payload, $caller, $bindings, ['approved_minutes']);
    }

    #[DataProvider('minuteModules')]
    public function test_only_the_resolved_supervisor_can_approve_minutes(string $module): void
    {
        [$class, $payload, , $bindings] = $this->minuteFixture($module, 'approve');
        $permission = $module === 'Cto' ? 'cto.approve' : 'overtime.approve';
        $unrelatedReviewer = $this->user($permission);

        $this->assertUnauthorizedRequest($class, $payload, $unrelatedReviewer, $bindings);
    }

    #[DataProvider('attendanceDates')]
    public function test_attendance_reference_must_match_the_requested_attendance_date(string $date, bool $valid): void
    {
        $caller = $this->user('attendance-corrections.create');
        $attendance = DailyAttendance::create(['employee_id' => $this->employee()->id, 'attendance_date' => '2026-10-01']);
        $payload = [
            'daily_attendance_id' => $attendance->id,
            'attendance_date' => $date,
            'requested_time_in' => '2026-10-01 08:00:00',
            'reason' => 'Missing time log.',
        ];

        if (! $valid) {
            $this->assertInvalidRequest(StoreAttendanceCorrectionRequest::class, $payload, $caller, [], ['daily_attendance_id']);

            return;
        }

        $validated = $this->validateRequest(StoreAttendanceCorrectionRequest::class, $payload, $caller);

        $this->assertSame($attendance->id, $validated['daily_attendance_id']);
    }

    #[DataProvider('invalidAttendanceDates')]
    public function test_invalid_attendance_fields_do_not_reach_cross_field_date_comparison(string $field, mixed $value): void
    {
        $caller = $this->user('attendance-corrections.create');
        $attendance = DailyAttendance::create(['employee_id' => $this->employee()->id, 'attendance_date' => '2026-10-01']);
        $payload = [
            'daily_attendance_id' => $attendance->id,
            'attendance_date' => '2026-10-01',
            'requested_time_in' => '2026-10-01 08:00:00',
            'requested_time_out' => '2026-10-01 17:00:00',
            'reason' => 'Missing time log.',
        ];
        $payload[$field] = $value;

        $this->assertInvalidRequest(StoreAttendanceCorrectionRequest::class, $payload, $caller, [], [$field]);
    }

    public static function validProfileChanges(): array
    {
        return [
            'EmployeeProfileChange complete whitelist' => [[
                'first_name' => 'Ada', 'middle_name' => null, 'last_name' => 'Reyes', 'suffix' => null,
                'birth_date' => '1990-01-01', 'gender' => 'female', 'civil_status' => 'single',
                'personal_email' => 'ada@example.test', 'mobile_number' => '09171234567',
                'address_line_1' => '123 Main Street', 'address_line_2' => null,
                'city' => 'Manila', 'province' => 'Metro Manila', 'postal_code' => '1000', 'country' => 'Philippines',
            ]],
            'EmployeeProfileChange single optional clearing' => [['personal_email' => null]],
            'EmployeeProfileChange name at length limit' => [['first_name' => str_repeat('A', 100)]],
        ];
    }

    public static function invalidProfileChanges(): array
    {
        $cases = [
            'EmployeeProfileChange empty changes' => [[], ['changes']],
            'EmployeeProfileChange nested field value' => [['city' => ['nested']], ['changes.city']],
            'EmployeeProfileChange invalid email' => [['personal_email' => 'invalid-email'], ['changes.personal_email']],
            'EmployeeProfileChange invalid birthday' => [['birth_date' => 'not-a-date'], ['changes.birth_date']],
        ];

        foreach (['first_name', 'last_name', 'country'] as $field) {
            $cases['EmployeeProfileChange null '.$field] = [[$field => null], ['changes.'.$field]];
            $cases['EmployeeProfileChange blank '.$field] = [[$field => ''], ['changes.'.$field]];
        }

        foreach (['user_id', 'company_email', 'employee_number', 'position_id', 'employment_status', 'unlisted_field'] as $field) {
            $cases['EmployeeProfileChange forbidden '.$field] = [[$field => 'forged'], ['changes']];
        }

        foreach (['first_name' => 100, 'middle_name' => 100, 'last_name' => 100, 'suffix' => 20, 'gender' => 30,
            'civil_status' => 30, 'mobile_number' => 50, 'address_line_1' => 255, 'address_line_2' => 255,
            'city' => 100, 'province' => 100, 'postal_code' => 20, 'country' => 100] as $field => $max) {
            $cases['EmployeeProfileChange overlong '.$field] = [[$field => str_repeat('A', $max + 1)], ['changes.'.$field]];
            $cases['EmployeeProfileChange nonstring '.$field] = [[$field => 123], ['changes.'.$field]];
        }

        return $cases;
    }

    public static function emptyActions(): array
    {
        return [
            'EmployeeProfileChange apply' => ['EmployeeProfileChange', 'App\\Http\\Requests\\ApplyEmployeeProfileChangeRequest', 'employeeProfileChangeRequest', 'profile-changes.approve'],
            'Announcements cancel schedule' => ['Announcements', 'App\\Http\\Requests\\CancelAnnouncementScheduleRequest', 'announcement', 'announcements.manage'],
            'Announcements deactivate' => ['Announcements', 'App\\Http\\Requests\\DeactivateAnnouncementRequest', 'announcement', 'announcements.manage'],
            'Announcements reactivate' => ['Announcements', 'App\\Http\\Requests\\ReactivateAnnouncementRequest', 'announcement', 'announcements.manage'],
        ];
    }

    public static function emptyActionAuthorization(): array
    {
        $cases = [];

        foreach (self::emptyActions() as $label => $arguments) {
            foreach (['no permission', 'inactive caller', 'no caller', 'missing model', 'scalar model', 'wrong model', 'wrong route key'] as $scenario) {
                $cases[$label.' '.$scenario] = [...$arguments, $scenario];
            }
        }

        return $cases;
    }

    public static function validSettingIntegers(): array
    {
        return self::pairedCases('SystemSettings', ['store', 'update'], [
            'zero integer' => 0, 'positive integer' => 42, 'negative integer' => -42,
            'zero string' => '0', 'positive string' => '42', 'negative string' => '-42',
            'nullable' => null, 'largest integer' => (string) PHP_INT_MAX, 'smallest integer' => (string) PHP_INT_MIN,
        ]);
    }

    public static function invalidSettingIntegers(): array
    {
        return self::pairedCases('SystemSettings', ['store', 'update'], [
            'leading zero' => '001', 'padded zero' => '00', 'negative leading zero' => '-001',
            'positive overflow' => '999999999999999999999999', 'negative overflow' => '-999999999999999999999999',
            'decimal' => '1.5', 'float' => 1.5, 'boolean true' => true, 'boolean false' => false,
            'array' => [1], 'malformed integer' => 'one', 'exponent' => '1e2',
        ]);
    }

    public static function validLeaveUnits(): array
    {
        return self::pairedCases('Leave', ['store', 'approve'], [
            'minimum units' => '0.01', 'integer units' => 1, 'decimal units' => '1.25',
            'padded nonzero' => '0001.20', 'database maximum' => '999999.99',
        ]);
    }

    public static function invalidLeaveUnits(): array
    {
        return self::pairedCases('Leave', ['store', 'approve'], [
            'zero' => 0, 'zero string' => '0', 'zero decimal' => '0.00', 'padded zero' => '00',
            'padded decimal zero' => '000.00', 'negative units' => '-0.01', 'overflow' => '1000000.00',
            'overprecision' => '1.001', 'exponent' => '1e2', 'malformed units' => 'one', 'array' => [1],
        ]);
    }

    public static function leaveModule(): array
    {
        return ['Leave requested approval maximum' => ['Leave']];
    }

    public static function crossYearLeave(): array
    {
        return ['Leave balance managed cross year' => [true], 'Leave nonbalance cross year' => [false]];
    }

    public static function invalidLeaveDates(): array
    {
        return [
            'Leave malformed start date' => ['start_date', 'not-a-date'],
            'Leave array start date' => ['start_date', ['2026-10-01']],
            'Leave malformed end date' => ['end_date', 'not-a-date'],
        ];
    }

    public static function minuteModules(): array
    {
        return ['Cto resolved approver' => ['Cto'], 'Overtime resolved approver' => ['Overtime']];
    }

    public static function validMinuteIntervals(): array
    {
        $cases = [];

        foreach (['Cto', 'Overtime'] as $module) {
            $cases[$module.' exact one minute'] = [$module, '2026-10-03 08:01:00'];
            $cases[$module.' one minute and seconds'] = [$module, '2026-10-03 08:01:59'];
        }

        return $cases;
    }

    public static function invalidMinuteIntervals(): array
    {
        $cases = [];

        foreach (['Cto', 'Overtime'] as $module) {
            foreach ([
                'subminute' => ['end_at', '2026-10-03 08:00:59'],
                'same instant' => ['end_at', '2026-10-03 08:00:00'],
                'reversed interval' => ['end_at', '2026-10-03 07:59:59'],
                'malformed start' => ['start_at', 'not-a-date'],
                'array start' => ['start_at', ['2026-10-03 08:00:00']],
                'malformed end' => ['end_at', 'not-a-date'],
            ] as $label => [$field, $value]) {
                $cases[$module.' '.$label] = [$module, $field, $value];
            }
        }

        return $cases;
    }

    public static function validMinuteApprovals(): array
    {
        return [
            'Cto minimum approved minute' => ['Cto', 1], 'Cto full requested approval' => ['Cto', 60],
            'Overtime minimum approved minute' => ['Overtime', 1], 'Overtime full requested approval' => ['Overtime', 60],
        ];
    }

    public static function invalidMinuteApprovals(): array
    {
        $cases = [];

        foreach (['Cto', 'Overtime'] as $module) {
            foreach (['zero' => 0, 'negative' => -1, 'greater than requested' => 61, 'decimal' => '1.5', 'array' => [1]] as $label => $value) {
                $cases[$module.' '.$label.' approved minutes'] = [$module, $value];
            }
        }

        return $cases;
    }

    public static function attendanceDates(): array
    {
        return [
            'AttendanceCorrection matching attendance date' => ['2026-10-01', true],
            'AttendanceCorrection mismatched attendance date' => ['2026-10-02', false],
        ];
    }

    public static function invalidAttendanceDates(): array
    {
        return [
            'AttendanceCorrection malformed attendance date' => ['attendance_date', 'not-a-date'],
            'AttendanceCorrection array attendance date' => ['attendance_date', ['2026-10-01']],
            'AttendanceCorrection array requested time in' => ['requested_time_in', ['2026-10-01 08:00:00']],
            'AttendanceCorrection array requested time out' => ['requested_time_out', ['2026-10-01 17:00:00']],
            'AttendanceCorrection boolean daily attendance' => ['daily_attendance_id', true],
            'AttendanceCorrection unknown daily attendance' => ['daily_attendance_id', 987654321],
        ];
    }

    #[DataProvider('attendanceTimePairs')]
    public function test_attendance_correction_requires_filled_ordered_times(mixed $timeIn, mixed $timeOut, bool $valid): void
    {
        $payload = [
            'attendance_date' => '2026-10-01',
            'requested_time_in' => $timeIn,
            'requested_time_out' => $timeOut,
            'reason' => 'Missing clock record.',
        ];
        $caller = $this->user('attendance-corrections.create');

        if (! $valid) {
            $this->assertInvalidRequest(StoreAttendanceCorrectionRequest::class, $payload, $caller);

            return;
        }

        $validated = $this->validateRequest(StoreAttendanceCorrectionRequest::class, $payload, $caller);
        $this->assertSame($timeIn, $validated['requested_time_in']);
        $this->assertSame($timeOut, $validated['requested_time_out']);
    }

    public static function attendanceTimePairs(): array
    {
        return [
            'AttendanceCorrection null times' => [null, null, false],
            'AttendanceCorrection empty times' => ['', '', false],
            'AttendanceCorrection reversed times' => ['2026-10-01 17:00:00', '2026-10-01 08:00:00', false],
            'AttendanceCorrection equal times' => ['2026-10-01 08:00:00', '2026-10-01 08:00:00', true],
            'AttendanceCorrection only time in' => ['2026-10-01 08:00:00', null, true],
            'AttendanceCorrection only time out' => [null, '2026-10-01 17:00:00', true],
        ];
    }

    private static function pairedCases(string $module, array $operations, array $values): array
    {
        $cases = [];

        foreach ($operations as $operation) {
            foreach ($values as $label => $value) {
                $cases[$module.' '.$operation.' '.$label] = [$operation, $value];
            }
        }

        return $cases;
    }

    private function emptyActionFixture(string $module, string $class, string $key, string $permission): array
    {
        $caller = $this->user($permission);
        $model = $module === 'EmployeeProfileChange'
            ? EmployeeProfileChangeRequest::create(['employee_id' => $this->employee()->id, 'status' => 'approved'])
            : Announcement::create([
                'title' => 'Upcoming holiday', 'content' => 'Office schedule advisory.',
                'published_at' => '2026-10-05 08:00:00',
                'is_active' => ! str_contains($class, 'Reactivate'),
            ]);

        return [$caller, [$key => $model]];
    }

    private function settingFixture(string $operation, mixed $value): array
    {
        $caller = $this->user('settings.manage');

        if ($operation === 'store') {
            return [StoreSystemSettingRequest::class, [
                'group' => 'general', 'key' => 'integer_limit', 'type' => 'integer', 'value' => $value,
            ], $caller, []];
        }

        $setting = SystemSetting::create(['group' => 'general', 'key' => 'integer_limit', 'type' => 'integer', 'value' => '1']);

        return [UpdateSystemSettingRequest::class, ['value' => $value], $caller, ['systemSetting' => $setting]];
    }

    private function leaveFixture(string $operation, bool $requiresBalance = true): array
    {
        $leaveType = LeaveType::create([
            'code' => 'TEST-'.Str::uuid(), 'name' => 'Leave validation fixture',
            'requires_balance' => $requiresBalance, 'is_active' => true,
        ]);

        if ($operation === 'store') {
            return [StoreLeaveRequest::class, [
                'leave_type_id' => $leaveType->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-01',
                'day_portion' => 'full_day', 'requested_units' => '1.25', 'reason' => 'Personal appointment.',
            ], $this->user('leave.create'), [], 'requested_units'];
        }

        $caller = $this->user('leave.approve');
        $supervisor = $this->employee($caller);
        $employee = $this->employee(attributes: ['supervisor_employee_id' => $supervisor->id]);
        $request = LeaveRequest::create([
            'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
            'start_date' => '2026-10-01', 'end_date' => '2026-10-01', 'day_portion' => 'full_day',
            'requested_units' => '999999.99', 'reason' => 'Personal appointment.',
        ]);

        return [ApproveLeaveRequest::class, ['approved_units' => '1.25'], $caller, ['leaveRequest' => $request], 'approved_units'];
    }

    private function minuteFixture(string $module, string $operation): array
    {
        $prefix = $module === 'Cto' ? 'cto' : 'overtime';

        if ($operation === 'store') {
            return [$module === 'Cto' ? StoreCtoRequest::class : StoreOvertimeRequest::class, [
                'start_at' => '2026-10-03 08:00:00', 'end_at' => '2026-10-03 09:00:00',
                'reason' => 'Personal appointment.',
            ], $this->user($prefix.'.create'), []];
        }

        $caller = $this->user($prefix.'.approve');
        $supervisor = $this->employee($caller);
        $employee = $this->employee(attributes: ['supervisor_employee_id' => $supervisor->id]);
        $attributes = [
            'employee_id' => $employee->id, 'start_at' => '2026-10-03 08:00:00',
            'end_at' => '2026-10-03 09:00:00', 'requested_minutes' => 60, 'reason' => 'Personal appointment.',
        ];
        $request = $module === 'Cto' ? CtoRequest::create($attributes) : OvertimeRequest::create($attributes);

        return [$module === 'Cto' ? ApproveCtoRequest::class : ApproveOvertimeRequest::class,
            ['approved_minutes' => 30], $caller, [$prefix.'Request' => $request]];
    }

    private function assertValidatedPayload(array $expected, array $actual): void
    {
        $sort = function (array $values) use (&$sort): array {
            foreach ($values as &$value) {
                if (is_array($value)) {
                    $value = $sort($value);
                }
            }

            ksort($values);

            return $values;
        };

        $this->assertSame($sort($expected), $sort($actual));
    }
}
