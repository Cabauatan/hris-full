<?php

namespace Tests\Feature;

use App\Models\AttendanceCorrectionRequest;
use App\Models\CtoBalance;
use App\Models\CtoCreditTransaction;
use App\Models\CtoRequest;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\EmployeeProfileChangeRequest;
use App\Models\EmployeeScheduleAssignment;
use App\Models\LeaveBalance;
use App\Models\LeaveCreditTransaction;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OfficialBusinessRequest;
use App\Models\OvertimeRequest;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\TimeLog;
use App\Models\TravelOrderRequest;
use App\Models\User;
use App\Models\WorkSchedule;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class EmployeeSelfServiceApiTest extends FormRequestTestCase
{
    #[DataProvider('endpoints')]
    public function test_self_service_requires_authentication(string $path): void
    {
        $this->getJson('/api/v1/ess/'.$path)->assertUnauthorized();
    }

    #[DataProvider('endpoints')]
    public function test_self_service_rejects_accounts_without_employee_profiles(string $path): void
    {
        $this->actingAs($this->roleUser('super_admin'));
        $this->getJson('/api/v1/ess/'.$path)->assertForbidden();
    }

    #[DataProvider('endpoints')]
    public function test_self_service_rejects_inactive_employee_accounts(string $path): void
    {
        $actor = $this->roleUser('employee');
        $this->employee($actor);
        $actor->update(['is_active' => false]);
        $this->actingAs($actor);
        $this->getJson('/api/v1/ess/'.$path)->assertForbidden();
    }

    #[DataProvider('collections')]
    public function test_self_service_requires_the_modules_view_permission(string $path, string $permission): void
    {
        $actor = $this->user();
        $this->employee($actor);
        $this->actingAs($actor);
        $this->getJson('/api/v1/ess/'.$path)->assertForbidden();
    }

    #[DataProvider('collections')]
    public function test_seeded_employee_role_reads_only_own_records_with_safe_resources(string $path, string $permission): void
    {
        $actor = $this->roleUser('employee');
        $own = $this->employee($actor);
        $wanted = $this->record($path, $own, $actor);
        $this->record($path, $this->employee(), $this->user());
        $this->actingAs($actor);
        $response = $this->getJson('/api/v1/ess/'.$path)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $wanted->id)->assertJsonPath('data.0.employee_id', $own->id)->assertJsonStructure(['data', 'links', 'meta']);
        $this->assertSafeResource($response->json('data.0'));
    }

    #[DataProvider('privilegedRoles')]
    public function test_privileged_self_service_stays_self_only_and_filters_cannot_broaden_scope(string $role): void
    {
        $actor = $this->roleUser($role);
        $own = $this->employee($actor);
        $report = $this->employee(null, ['supervisor_employee_id' => $own->id]);
        $outsider = $this->employee();
        $otherActor = $this->user();
        $this->actingAs($actor);
        foreach (self::collections() as [$path, $permission]) {
            if (! $actor->hasPermission($permission)) {
                $this->getJson('/api/v1/ess/'.$path)->assertForbidden();

                continue;
            }
            $wanted = $this->record($path, $own, $actor);
            $this->record($path, $report, $otherActor);
            $hidden = $this->record($path, $outsider, $otherActor);
            $this->getJson('/api/v1/ess/'.$path)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $wanted->id);
            $response = $this->getJson('/api/v1/ess/'.$path.'?employee_id='.$outsider->id)->assertOk();
            $this->assertNotContains($hidden->id, array_column($response->json('data'), 'id'), 'A forged employee filter must not reveal another employee through '.$path.'.');
            foreach ($response->json('data') as $item) {
                $this->assertSame($own->id, $item['employee_id']);
            }
        }
    }

    public function test_profile_is_the_authenticated_employee_and_contains_no_private_child_records(): void
    {
        $actor = $this->roleUser('employee');
        $own = $this->employee($actor, ['personal_email' => 'self@example.test', 'birth_date' => '1990-02-03']);
        $outsider = $this->employee();
        $this->actingAs($actor);
        $this->getJson('/api/v1/ess/profile?employee_id='.$outsider->id)->assertOk()->assertJsonPath('data.id', $own->id)->assertJsonPath('data.personal_email', 'self@example.test')->assertJsonPath('data.birth_date', '1990-02-03')->assertJsonMissingPath('data.compensations')->assertJsonMissingPath('data.documents')->assertJsonMissingPath('data.user')->assertJsonMissingPath('data.password');
    }

    public function test_profile_owner_needs_no_employees_view_permission_but_cannot_bypass_profile_change_review(): void
    {
        $actor = $this->user();
        $own = $this->employee($actor);
        $this->actingAs($actor);
        $this->getJson('/api/v1/ess/profile')->assertOk()->assertJsonPath('data.id', $own->id);
        $this->patchJson('/api/v1/ess/profile', ['first_name' => 'Unreviewed'])->assertStatus(405);
        $this->patchJson('/api/v1/employees/'.$own->id, ['employee_number' => $own->employee_number, 'first_name' => 'Unreviewed', 'last_name' => 'Employee', 'hire_date' => '2026-01-01', 'employment_type' => 'regular'])->assertForbidden();
        $this->assertSame('Request', $own->fresh()->first_name);
    }

    public function test_profile_change_submission_does_not_modify_employee_until_review_and_apply(): void
    {
        $actor = $this->roleUser('employee');
        $own = $this->employee($actor);
        $this->actingAs($actor);
        $response = $this->postJson('/api/v1/employee-profile-change-requests', ['changes' => ['first_name' => 'Requested Name'], 'reason' => 'Correct spelling'])->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->assertSame('Request', $own->fresh()->first_name);
        $this->postJson('/api/v1/employee-profile-change-requests/'.$response->json('data.id').'/apply')->assertForbidden();
        $this->assertSame('Request', $own->fresh()->first_name);
    }

    public function test_balances_and_ledgers_preserve_decimal_units_integer_minutes_and_dates(): void
    {
        $actor = $this->roleUser('employee');
        $own = $this->employee($actor);
        $this->record('leave-balances', $own, $actor);
        $this->record('cto-balances', $own, $actor);
        $this->record('leave-credit-transactions', $own, $actor);
        $this->record('cto-credit-transactions', $own, $actor);
        $this->actingAs($actor);
        $this->getJson('/api/v1/ess/leave-balances')->assertOk()->assertJsonPath('data.0.balance', '12.34')->assertJsonPath('data.0.year', 2026);
        $this->getJson('/api/v1/ess/cto-balances')->assertOk()->assertJsonPath('data.0.balance_minutes', 125);
        $this->getJson('/api/v1/ess/leave-credit-transactions')->assertOk()->assertJsonPath('data.0.units', '-1.25')->assertJsonPath('data.0.effective_date', '2026-10-04');
        $this->getJson('/api/v1/ess/cto-credit-transactions')->assertOk()->assertJsonPath('data.0.minutes', -30)->assertJsonPath('data.0.effective_date', '2026-10-04');
    }

    public function test_payslip_self_service_hides_unpublished_records_even_from_super_admin(): void
    {
        $actor = $this->roleUser('super_admin');
        $own = $this->employee($actor);
        $published = $this->record('payslips', $own, $actor);
        $unpublished = $this->record('payslips', $own, $actor);
        $unpublished->update(['published_at' => null]);
        $this->actingAs($actor);
        $this->getJson('/api/v1/ess/payslips')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $published->id)->assertJsonMissingPath('data.0.file_path')->assertJsonMissingPath('data.0.payroll_entry');
    }

    public function test_self_service_pagination_is_bounded_and_uses_validated_filters(): void
    {
        $actor = $this->roleUser('employee');
        $own = $this->employee($actor);
        $this->record('time-logs', $own, $actor);
        $latest = $this->record('time-logs', $own, $actor);
        $this->actingAs($actor);
        $this->getJson('/api/v1/ess/time-logs?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $latest->id)->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/ess/time-logs?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson('/api/v1/ess/time-logs?employee_id%5B%5D='.$own->id)->assertUnprocessable()->assertJsonValidationErrors('employee_id');
    }

    #[DataProvider('workflows')]
    public function test_seeded_employee_role_can_submit_and_cancel_own_workflow_but_cannot_approve_or_cancel_another(string $path): void
    {
        $actor = $this->roleUser('employee');
        $own = $this->employee($actor);
        $otherActor = $this->user();
        $otherEmployee = $this->employee($otherActor);
        $this->actingAs($actor);
        $payload = $this->submission($path, $own) + ['employee_id' => $otherEmployee->id, 'created_by_user_id' => $otherActor->id, 'status' => 'approved'];
        $response = $this->postJson('/api/v1/'.$path, $payload)->assertCreated()->assertJsonPath('data.employee_id', $own->id)->assertJsonPath('data.created_by_user_id', $actor->id)->assertJsonPath('data.status', 'pending');
        $id = $response->json('data.id');
        $this->postJson('/api/v1/'.$path.'/'.$id.'/approve', ['approved_units' => '1.00', 'approved_minutes' => 60])->assertForbidden();
        $hidden = $this->record($path, $otherEmployee, $otherActor);
        $this->postJson('/api/v1/'.$path.'/'.$hidden->id.'/cancel', ['reason' => 'Forged cancellation'])->assertForbidden();
        $this->assertSame('pending', $hidden->fresh()->status);
        $this->postJson('/api/v1/'.$path.'/'.$id.'/cancel', ['reason' => 'No longer needed'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas(self::model($path)::make()->getTable(), ['id' => $id, 'employee_id' => $own->id, 'status' => 'cancelled']);
    }

    /** @return iterable<string, array{string}> */
    public static function endpoints(): iterable
    {
        yield 'Profile' => ['profile'];
        foreach (self::collections() as $label => [$path]) {
            yield $label => [$path];
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function collections(): iterable
    {
        yield 'TimeLog' => ['time-logs', 'attendance.view'];
        yield 'DailyAttendance' => ['daily-attendances', 'attendance.view'];
        yield 'ScheduleAssignment' => ['schedule-assignments', 'schedules.view'];
        yield 'EmployeeProfileChange' => ['employee-profile-change-requests', 'profile-changes.view'];
        yield 'AttendanceCorrection' => ['attendance-correction-requests', 'attendance-corrections.view'];
        yield 'Leave' => ['leave-requests', 'leave.view'];
        yield 'Cto' => ['cto-requests', 'cto.view'];
        yield 'OfficialBusiness' => ['official-business-requests', 'official-business.view'];
        yield 'TravelOrder' => ['travel-order-requests', 'travel-orders.view'];
        yield 'Overtime' => ['overtime-requests', 'overtime.view'];
        yield 'LeaveBalance' => ['leave-balances', 'leave.view'];
        yield 'CtoBalance' => ['cto-balances', 'cto.view'];
        yield 'LeaveLedger' => ['leave-credit-transactions', 'leave.view'];
        yield 'CtoLedger' => ['cto-credit-transactions', 'cto.view'];
        yield 'Payslip' => ['payslips', 'payslips.view'];
    }

    /** @return iterable<string, array{string}> */
    public static function privilegedRoles(): iterable
    {
        yield 'HR' => ['hr_admin'];
        yield 'Global administrator' => ['super_admin'];
        yield 'Team supervisor' => ['supervisor'];
    }

    /** @return iterable<string, array{string}> */
    public static function workflows(): iterable
    {
        foreach (['employee-profile-change-requests', 'attendance-correction-requests', 'leave-requests', 'cto-requests', 'official-business-requests', 'travel-order-requests', 'overtime-requests'] as $path) {
            yield $path => [$path];
        }
    }

    private function roleUser(string $role): User
    {
        $this->seed(RolePermissionSeeder::class);
        $actor = $this->user();
        $actor->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $actor;
    }

    private function record(string $path, Employee $employee, User $actor): Model
    {
        $attributes = match ($path) {
            'time-logs' => ['logged_at' => '2026-10-04 08:00:00', 'log_type' => 'in'],
            'daily-attendances' => ['attendance_date' => '2026-10-04', 'worked_minutes' => 480],
            'schedule-assignments' => ['work_schedule_id' => WorkSchedule::query()->create(['code' => 'ESS-'.Str::uuid(), 'name' => 'Self-service schedule', 'is_active' => true])->id, 'effective_from' => '2026-01-01'],
            'leave-balances' => ['leave_type_id' => $this->leaveType()->id, 'year' => 2026, 'balance' => '12.34'],
            'cto-balances' => ['balance_minutes' => 125],
            'leave-credit-transactions' => ['leave_type_id' => $this->leaveType()->id, 'year' => 2026, 'units' => '-1.25', 'transaction_type' => 'adjustment', 'effective_date' => '2026-10-04'],
            'cto-credit-transactions' => ['minutes' => -30, 'transaction_type' => 'adjustment', 'effective_date' => '2026-10-04'],
            'payslips' => ['payroll_entry_id' => $this->payrollEntry($employee)->id, 'file_path' => 'payslips/private.pdf', 'generated_at' => '2026-10-04 08:00:00', 'published_at' => '2026-10-04 09:00:00'],
            default => $this->submission($path, $employee),
        };
        $changes = $attributes['changes'] ?? null;
        unset($attributes['changes']);
        if (in_array($path, ['cto-requests', 'overtime-requests'], true)) {
            $attributes['requested_minutes'] = 60;
        }
        $record = self::model($path)::query()->create($attributes + ['employee_id' => $employee->id, 'created_by_user_id' => $actor->id]);
        if ($record instanceof EmployeeProfileChangeRequest) {
            foreach ($changes as $field => $value) {
                $record->items()->create(['field_name' => $field, 'old_value' => $employee->{$field}, 'new_value' => $value]);
            }
        }

        return $record;
    }

    /** @return array<string, mixed> */
    private function submission(string $path, Employee $employee): array
    {
        return match ($path) {
            'employee-profile-change-requests' => ['changes' => ['first_name' => 'Requested Name'], 'reason' => 'Update profile', 'document_path' => 'documents/private.pdf'],
            'attendance-correction-requests' => ['attendance_date' => '2026-10-04', 'requested_time_in' => '2026-10-04 08:00:00', 'reason' => 'Correct scan', 'document_path' => 'documents/private.pdf'],
            'leave-requests' => ['leave_type_id' => $this->leaveType()->id, 'start_date' => '2026-10-04', 'end_date' => '2026-10-04', 'day_portion' => 'full_day', 'requested_units' => '1.00', 'reason' => 'Appointment', 'document_path' => 'documents/private.pdf'],
            'cto-requests', 'overtime-requests' => ['start_at' => '2026-10-04 08:00:00', 'end_at' => '2026-10-04 09:00:00', 'reason' => 'Appointment'],
            'official-business-requests', 'travel-order-requests' => ['start_at' => '2026-10-04 08:00:00', 'end_at' => '2026-10-04 09:00:00', 'destination' => 'Head office', 'purpose' => 'Official meeting', 'document_path' => 'documents/private.pdf'],
        };
    }

    private function leaveType(): LeaveType
    {
        return LeaveType::query()->create(['code' => 'ESS-'.Str::uuid(), 'name' => 'Self-service leave', 'requires_balance' => false, 'allows_partial_day' => true, 'is_active' => true]);
    }

    private function payrollEntry(Employee $employee): PayrollEntry
    {
        $period = PayrollPeriod::query()->create(['code' => 'ESS-'.Str::uuid(), 'period_start' => '2026-10-01', 'period_end' => '2026-10-15', 'pay_date' => '2026-10-16', 'pay_frequency' => 'semi_monthly']);
        $run = PayrollRun::query()->create(['payroll_period_id' => $period->id, 'run_number' => 1, 'status' => 'finalized']);

        return PayrollEntry::query()->create(['payroll_run_id' => $run->id, 'employee_id' => $employee->id, 'basic_salary' => '12000.00', 'pay_frequency' => 'semi_monthly', 'currency' => 'PHP']);
    }

    /** @return class-string<Model> */
    private static function model(string $path): string
    {
        return match ($path) {
            'time-logs' => TimeLog::class,
            'daily-attendances' => DailyAttendance::class,
            'schedule-assignments' => EmployeeScheduleAssignment::class,
            'employee-profile-change-requests' => EmployeeProfileChangeRequest::class,
            'attendance-correction-requests' => AttendanceCorrectionRequest::class,
            'leave-requests' => LeaveRequest::class,
            'cto-requests' => CtoRequest::class,
            'official-business-requests' => OfficialBusinessRequest::class,
            'travel-order-requests' => TravelOrderRequest::class,
            'overtime-requests' => OvertimeRequest::class,
            'leave-balances' => LeaveBalance::class,
            'cto-balances' => CtoBalance::class,
            'leave-credit-transactions' => LeaveCreditTransaction::class,
            'cto-credit-transactions' => CtoCreditTransaction::class,
            'payslips' => Payslip::class,
        };
    }

    /** @param array<string, mixed> $resource */
    private function assertSafeResource(array $resource): void
    {
        foreach (['file_path', 'document_path', 'password', 'remember_token', 'employee', 'creator', 'reviewer', 'payroll_entry', 'compensations', 'documents'] as $field) {
            $this->assertArrayNotHasKey($field, $resource);
        }
    }
}
