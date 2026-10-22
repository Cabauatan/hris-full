<?php

namespace Tests\Feature;

use App\Http\Requests\ExportReportRequest;
use App\Http\Requests\GetReportRequest;
use App\Http\Requests\IndexReportRequest;
use App\Models\ApprovalDelegation;
use App\Models\AttendanceCorrectionRequest;
use App\Models\CtoBalance;
use App\Models\CtoRequest;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\EmployeeProfileChangeRequest;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OfficialBusinessRequest;
use App\Models\OvertimeRequest;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\TravelOrderRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\FormRequestTestCase;

class ReportApiTest extends FormRequestTestCase
{
    #[TestWith(['/api/v1/reports'])]
    #[TestWith(['/api/v1/reports/attendance'])]
    #[TestWith(['/api/v1/reports/attendance/export'])]
    public function test_guest_receives_401(string $url): void
    {
        $this->getJson($url)->assertUnauthorized();
    }

    #[TestWith(['/api/v1/reports'])]
    #[TestWith(['/api/v1/reports/attendance'])]
    #[TestWith(['/api/v1/reports/attendance/export'])]
    public function test_inactive_account_receives_403(string $url): void
    {
        $actor = $this->user('attendance.view');
        $actor->update(['is_active' => false]);

        $this->actingAs($actor)->getJson($url)->assertForbidden();
    }

    #[TestWith(['/api/v1/reports'])]
    #[TestWith(['/api/v1/reports/attendance'])]
    #[TestWith(['/api/v1/reports/attendance/export'])]
    public function test_permissionless_account_receives_403(string $url): void
    {
        $this->actingAs($this->user())->getJson($url)->assertForbidden();
    }

    #[DataProvider('reportPermissions')]
    public function test_gates_require_active_account_and_module_permission(string $report, string $permission): void
    {
        $actor = $this->user($permission);
        $denied = $this->user('organization.view');

        $this->assertTrue($actor->can('viewReports'));
        $this->assertTrue($actor->can('viewReport', [$report]));
        $this->assertTrue($actor->can('exportReport', [$report]));
        $this->assertFalse($denied->can('viewReport', [$report]));
        $this->assertFalse($denied->can('exportReport', [$report]));
        $actor->permissions()->detach();
        $this->assertFalse($actor->can('viewReports'));
        $this->assertFalse($actor->can('viewReport', [$report]));
        $actor->update(['is_active' => false]);
        $this->assertFalse($actor->can('exportReport', [$report]));
    }

    public static function reportPermissions(): array
    {
        return [
            'roster' => ['employees', 'employees.view-team'], 'attendance' => ['attendance', 'attendance.view'],
            'leave credits' => ['leave-balances', 'leave.view'], 'CTO credits' => ['cto-balances', 'cto.view'],
            'payroll' => ['payroll', 'payroll.view'], 'leave' => ['leave', 'leave.view'], 'CTO' => ['cto', 'cto.view'],
            'overtime' => ['overtime', 'overtime.view'], 'official business' => ['official_business', 'official-business.view'],
            'travel order' => ['travel_order', 'travel-orders.view'], 'correction' => ['attendance_correction', 'attendance-corrections.view'],
            'profile' => ['profile_changes', 'profile-changes.view'],
        ];
    }

    public function test_catalog_returns_only_authorized_public_descriptors_and_local_defaults(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $actor = $this->user('attendance.view', 'leave.view');
        $this->employee($actor);

        $response = $this->actingAs($actor)->getJson('/api/v1/reports')->assertOk()
            ->assertJsonPath('data.default_filters', ['scope' => 'visible', 'start' => '2026-10-01', 'end' => '2026-10-31', 'year' => 2026])
            ->assertJsonPath('data.available_scopes', ['mine', 'visible'])->assertJsonPath('data.export', ['format' => 'csv', 'max_rows' => 10000]);
        $this->assertSame(['attendance', 'leave-balances', 'leave'], array_column($response->json('data.reports'), 'key'));
        foreach ($response->json('data.reports') as $descriptor) {
            $this->assertSame(['label', 'description', 'kind', 'date_basis', 'statuses', 'year_filter', 'columns', 'key', 'can_export'], array_keys($descriptor));
            $this->assertTrue($descriptor['can_export']);
            foreach ($descriptor['columns'] as $column) {
                $this->assertSame(['key', 'label', 'type'], array_keys($column));
                $this->assertNotSame('id', $column['key']);
                $this->assertFalse(str_ends_with($column['key'], '_id'));
            }
        }
    }

    public function test_unlinked_catalog_exposes_visible_only_and_global_employee_access_does_not_grant_payroll(): void
    {
        $response = $this->actingAs($this->user('employees.view'))->getJson('/api/v1/reports')->assertOk()->assertJsonPath('data.available_scopes', ['visible']);

        $this->assertSame(['employees'], array_column($response->json('data.reports'), 'key'));
        $this->getJson('/api/v1/reports/payroll')->assertForbidden();
    }

    #[TestWith(['unknown'])]
    #[TestWith(['attendance%27%20OR%201%3D1'])]
    #[TestWith(['employee_compensations'])]
    public function test_unknown_or_injected_route_returns_404(string $report): void
    {
        $this->actingAs($this->user('attendance.view'))->getJson('/api/v1/reports/'.$report)->assertNotFound();
        $this->getJson('/api/v1/reports/'.$report.'/export')->assertNotFound();
    }

    #[DataProvider('reportPermissions')]
    public function test_empty_preview_preserves_complete_pagination_and_available_export(string $report, string $permission): void
    {
        $this->actingAs($this->user($permission))->getJson($this->url($report))->assertOk()
            ->assertJsonPath('data.report.key', $report)->assertJsonPath('data.rows', [])->assertJsonPath('data.summary.records', 0)
            ->assertJsonPath('data.pagination', ['current_page' => 1, 'per_page' => 20, 'last_page' => 1, 'total' => 0])
            ->assertJsonPath('data.export.available', true)->assertJsonPath('data.available_scopes', ['visible']);
    }

    #[DataProvider('reportPermissions')]
    public function test_unlinked_account_cannot_request_mine(string $report, string $permission): void
    {
        $this->actingAs($this->user($permission))->getJson($this->url($report, ['scope' => 'mine']))->assertForbidden();
    }

    #[DataProvider('employeeScopedReports')]
    public function test_scope_includes_own_direct_team_and_excludes_grandchildren_and_others(string $report, string $permission): void
    {
        $actor = $this->user($permission, 'employees.view-team');
        $own = $this->employee($actor);
        $direct = $this->employee(null, ['supervisor_employee_id' => $own->id]);
        $first = $this->record($report, $own);
        $second = $this->record($report, $direct);
        $this->record($report, $this->employee(null, ['supervisor_employee_id' => $direct->id]));
        $this->record($report, $this->employee());

        $visible = $this->actingAs($actor)->getJson($this->url($report, ['year' => $report === 'leave-balances' ? 2026 : null]))->assertOk()->assertJsonPath('data.summary.records', 2);
        $this->assertSame([$first->id, $second->id], array_column($visible->json('data.rows'), 'id'));
        $mine = $this->getJson($this->url($report, ['scope' => 'mine', 'year' => $report === 'leave-balances' ? 2026 : null]))->assertOk()->assertJsonPath('data.summary.records', 1);
        $this->assertSame([$first->id], array_column($mine->json('data.rows'), 'id'));
    }

    public static function employeeScopedReports(): array
    {
        return array_filter(self::reportPermissions(), fn (array $case): bool => ! in_array($case[0], ['payroll', 'profile_changes'], true));
    }

    #[DataProvider('employeeScopedReports')]
    public function test_employee_filter_intersects_existing_visibility_without_existence_leak(string $report, string $permission): void
    {
        $actor = $this->user($permission);
        $this->record($report, $this->employee($actor));
        $hidden = $this->employee();
        $this->record($report, $hidden);

        $this->actingAs($actor)->getJson($this->url($report, ['employee_id' => $hidden->id]))->assertOk()->assertJsonPath('data.rows', []);
        $this->getJson($this->url($report, ['employee_id' => 987654321]))->assertOk()->assertJsonPath('data.rows', []);
    }

    public function test_global_access_does_not_override_personal_scope_or_missing_module_permission(): void
    {
        $actor = $this->user('attendance.view', 'employees.view');
        $wanted = $this->record('attendance', $this->employee($actor));
        $this->record('attendance', $this->employee());

        $this->actingAs($actor)->getJson($this->url('attendance'))->assertOk()->assertJsonCount(2, 'data.rows');
        $this->getJson($this->url('attendance', ['scope' => 'mine']))->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.id', $wanted->id);
        $this->getJson($this->url('leave'))->assertForbidden();
    }

    public function test_profile_changes_do_not_inherit_team_visibility_or_expose_changes(): void
    {
        $actor = $this->user('profile-changes.view', 'employees.view-team');
        $own = $this->employee($actor);
        $wanted = $this->record('profile_changes', $own);
        $wanted->items()->create(['field_name' => 'mobile_number', 'old_value' => 'PRIVATE OLD', 'new_value' => 'PRIVATE NEW']);
        $this->record('profile_changes', $this->employee(null, ['supervisor_employee_id' => $own->id]));
        $this->record('profile_changes', $own, ['created_at' => '2026-11-01 00:00:00']);

        $response = $this->actingAs($actor)->getJson($this->url('profile_changes'))->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.id', $wanted->id);
        $this->assertStringNotContainsString('PRIVATE', $response->getContent());
    }

    #[DataProvider('delegatedWorkflows')]
    public function test_current_delegate_sees_only_workflow_and_expiry_removes_access(string $report, string $permission): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $approve = str_replace('.view', '.approve', $permission);
        $supervisor = $this->user($permission, $approve);
        $supervisorEmployee = $this->employee($supervisor);
        $actor = $this->user($permission, $approve);
        $this->employee($actor);
        $wanted = $this->record($report, $this->employee(null, ['supervisor_employee_id' => $supervisorEmployee->id]));
        $delegation = $this->delegation($supervisor, $actor);

        $this->actingAs($actor)->getJson($this->url($report))->assertOk()->assertJsonPath('data.rows.0.id', $wanted->id);
        $delegation->update(['ends_at' => '2026-10-05 09:59:59']);
        $this->getJson($this->url($report))->assertOk()->assertJsonPath('data.rows', []);
    }

    public static function delegatedWorkflows(): array
    {
        return array_filter(self::reportPermissions(), fn (array $case): bool => in_array($case[0], ['leave', 'cto', 'overtime', 'official_business', 'travel_order', 'attendance_correction'], true));
    }

    public function test_latest_delegation_and_permission_revocation_invalidate_workflow_access(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $supervisor = $this->user('cto.view', 'cto.approve');
        $supervisorEmployee = $this->employee($supervisor);
        $actor = $this->user('cto.view', 'cto.approve');
        $other = $this->user('cto.view', 'cto.approve');
        $this->record('cto', $this->employee(null, ['supervisor_employee_id' => $supervisorEmployee->id]));
        $this->delegation($supervisor, $actor);
        $latest = $this->delegation($supervisor, $other, ['starts_at' => '2026-10-04 00:00:00']);

        $this->actingAs($actor)->getJson($this->url('cto'))->assertOk()->assertJsonPath('data.rows', []);
        $latest->update(['is_active' => false]);
        $this->getJson($this->url('cto'))->assertOk()->assertJsonCount(1, 'data.rows');
        $supervisor->permissions()->detach();
        $this->getJson($this->url('cto'))->assertOk()->assertJsonPath('data.rows', []);
    }

    public function test_delegate_does_not_gain_attendance_or_balance_access(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $supervisor = $this->user('cto.approve');
        $supervisorEmployee = $this->employee($supervisor);
        $actor = $this->user('cto.view', 'cto.approve', 'attendance.view', 'leave.view');
        $this->employee($actor);
        $foreign = $this->employee(null, ['supervisor_employee_id' => $supervisorEmployee->id]);
        foreach (['attendance', 'cto-balances', 'leave-balances'] as $report) {
            $this->record($report, $foreign);
        }
        $this->delegation($supervisor, $actor);

        foreach (['attendance', 'cto-balances', 'leave-balances'] as $report) {
            $this->actingAs($actor)->getJson($this->url($report))->assertOk()->assertJsonPath('data.rows', []);
        }
    }

    public function test_attendance_summary_covers_filtered_pages_and_inclusive_dates(): void
    {
        $actor = $this->user('attendance.view');
        $own = $this->employee($actor);
        $first = $this->record('attendance', $own, ['attendance_date' => '2026-10-01', 'worked_minutes' => 100, 'late_minutes' => 3]);
        $second = $this->record('attendance', $own, ['attendance_date' => '2026-10-31', 'worked_minutes' => 200, 'undertime_minutes' => 7]);
        $this->record('attendance', $own, ['attendance_date' => '2026-09-30', 'worked_minutes' => 999]);
        $this->record('attendance', $own, ['attendance_date' => '2026-11-01', 'worked_minutes' => 999]);

        $response = $this->actingAs($actor)->getJson($this->url('attendance', ['per_page' => 1]))->assertOk()->assertJsonPath('data.summary.records', 2)
            ->assertJsonPath('data.pagination.total', 2)->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.id', $first->id);
        $this->assertSame(300, $this->metric($response, 'Worked Minutes'));
        $this->assertSame(3, $this->metric($response, 'Late Minutes'));
        $this->assertSame(7, $this->metric($response, 'Undertime Minutes'));
        $this->getJson($this->url('attendance', ['per_page' => 1, 'page' => 2]))->assertOk()->assertJsonPath('data.rows.0.id', $second->id);
        $this->getJson($this->url('attendance', ['per_page' => 1, 'page' => 3]))->assertOk()->assertJsonPath('data.rows', [])->assertJsonPath('data.summary.records', 2);
    }

    public function test_unknown_attendance_statuses_merge_without_inventing_missing_absences(): void
    {
        $actor = $this->user('attendance.view', 'employees.view');
        $this->employee();
        foreach ([null, '', 'unknown'] as $status) {
            $this->record('attendance', $this->employee(), ['status' => $status]);
        }

        $response = $this->actingAs($actor)->getJson($this->url('attendance'))->assertOk()->assertJsonPath('data.summary.records', 3);
        $this->assertSame(3, $this->metric($response, 'Unknown'));
        $this->assertSame([null, '', 'unknown'], array_column($response->json('data.rows'), 'status'));
    }

    public function test_leave_overlap_keeps_full_decimal_units_and_timed_windows_exclude_touching_boundaries(): void
    {
        $actor = $this->user('leave.view', 'cto.view');
        $own = $this->employee($actor);
        $leave = $this->record('leave', $own, ['start_date' => '2026-09-29', 'end_date' => '2026-10-02', 'requested_units' => '3.25', 'approved_units' => '2.50']);
        $cto = $this->record('cto', $own, ['start_at' => '2026-09-30 23:30:00', 'end_at' => '2026-10-01 00:30:00']);
        $this->record('cto', $own, ['start_at' => '2026-09-30 23:00:00', 'end_at' => '2026-10-01 00:00:00']);
        $this->record('cto', $own, ['start_at' => '2026-10-02 00:00:00', 'end_at' => '2026-10-02 01:00:00']);

        $this->actingAs($actor)->getJson($this->url('leave', ['start' => '2026-10-02', 'end' => '2026-10-02']))->assertOk()->assertJsonPath('data.rows.0.id', $leave->id)
            ->assertJsonPath('data.rows.0.requested_units', '3.25')->assertJsonPath('data.rows.0.approved_units', '2.50');
        $this->getJson($this->url('cto', ['start' => '2026-10-01', 'end' => '2026-10-01']))->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.id', $cto->id);
    }

    public function test_leave_year_and_current_cto_snapshot_preserve_decimal_and_integer_types(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $actor = $this->user('leave.view', 'cto.view');
        $own = $this->employee($actor);
        $this->record('leave-balances', $own, ['balance' => '12.34']);
        $this->record('leave-balances', $own, ['year' => 2025, 'balance' => '99.99']);
        $this->record('cto-balances', $own, ['balance_minutes' => 125]);

        $this->actingAs($actor)->getJson($this->url('leave-balances'))->assertOk()->assertJsonPath('data.filters.year', 2026)
            ->assertJsonPath('data.filters.start', null)->assertJsonPath('data.rows.0.balance', '12.34');
        $this->getJson($this->url('leave-balances', ['year' => 2025]))->assertOk()->assertJsonPath('data.rows.0.balance', '99.99');
        $this->getJson($this->url('cto-balances'))->assertOk()->assertJsonPath('data.filters.year', null)->assertJsonPath('data.rows.0.balance_minutes', 125);
    }

    public function test_payroll_has_global_permission_scope_and_exact_totals_separated_by_currency(): void
    {
        $actor = $this->user('payroll.view');
        $own = $this->employee($actor);
        $run = $this->payrollRun(['status' => 'completed']);
        $wanted = $this->entry($run, $own, ['gross_pay' => '100.10', 'total_deductions' => '10.01', 'net_pay' => '90.09']);
        $this->entry($run, $this->employee(), ['gross_pay' => '0.20', 'total_deductions' => '0.02', 'net_pay' => '0.18']);
        $this->entry($run, $this->employee(), ['currency' => 'USD', 'gross_pay' => '7.25', 'total_deductions' => '1.00', 'net_pay' => '6.25']);

        $response = $this->actingAs($actor)->getJson($this->url('payroll', ['per_page' => 1]))->assertOk()->assertJsonPath('data.summary.records', 3)->assertJsonPath('data.rows.0.gross_pay', '100.10');
        $this->assertSame('100.30', $this->metric($response, 'Gross Pay (PHP)'));
        $this->assertSame('10.03', $this->metric($response, 'Total Deductions (PHP)'));
        $this->assertSame('90.27', $this->metric($response, 'Net Pay (PHP)'));
        $this->assertSame('7.25', $this->metric($response, 'Gross Pay (USD)'));
        $this->getJson($this->url('payroll', ['scope' => 'mine']))->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.id', $wanted->id);
    }

    public function test_payroll_filters_coverage_overlap_and_run_status_instead_of_entry_status(): void
    {
        $actor = $this->user('payroll.view');
        $run = $this->payrollRun(['status' => 'finalized'], ['period_start' => '2026-09-20', 'period_end' => '2026-10-01']);
        $wanted = $this->entry($run, $this->employee(), ['status' => 'calculated']);
        $this->entry($this->payrollRun(['status' => 'draft']), $this->employee());
        $this->entry($this->payrollRun(['status' => 'finalized'], ['period_start' => '2026-09-01', 'period_end' => '2026-09-30']), $this->employee());

        $response = $this->actingAs($actor)->getJson($this->url('payroll', ['start' => '2026-10-01', 'end' => '2026-10-01', 'status' => 'finalized', 'payroll_run_id' => $run->id]))->assertOk()
            ->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.id', $wanted->id)->assertJsonPath('data.rows.0.status', 'finalized')->assertJsonPath('data.rows.0.entry_status', 'calculated');
        $columns = array_column($response->json('data.report.columns'), 'key');
        $this->assertNotContains('id', $columns);
        $this->assertNotContains('payroll_run_id', $columns);
        $this->assertContains('run_number', $columns);
        $this->assertContains('period_code', $columns);
        $this->getJson($this->url('payroll', ['payroll_run_id' => 987654321]))->assertOk()->assertJsonPath('data.rows', []);
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_or_unsupported_filter_returns_422_and_error_message(string $report, array $filters, string $field): void
    {
        $actor = $this->user('attendance.view', 'employees.view', 'leave.view', 'cto.view', 'payroll.view');
        $this->employee($actor);

        $response = $this->actingAs($actor)->getJson('/api/v1/reports/'.$report.'?'.http_build_query($filters))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertNotEmpty($response->json('errors.'.$field.'.0'));
    }

    public static function invalidQueries(): array
    {
        return [
            'array scope' => ['attendance', ['scope' => ['visible']], 'scope'], 'unknown scope' => ['attendance', ['scope' => 'organization'], 'scope'],
            'only start' => ['attendance', ['start' => '2026-10-01'], 'end'], 'only end' => ['attendance', ['end' => '2026-10-31'], 'start'],
            'invalid leap' => ['attendance', ['start' => '2026-02-29', 'end' => '2026-03-01'], 'start'],
            'noncanonical' => ['attendance', ['start' => '2026-1-1', 'end' => '2026-01-31'], 'start'],
            'array date' => ['attendance', ['start' => ['2026-10-01'], 'end' => '2026-10-31'], 'start'],
            'empty date' => ['attendance', ['start' => '', 'end' => '2026-10-31'], 'start'],
            'reversed' => ['attendance', ['start' => '2026-10-02', 'end' => '2026-10-01'], 'end'],
            '367 days' => ['attendance', ['start' => '2026-01-01', 'end' => '2027-01-02'], 'end'],
            'employee zero' => ['attendance', ['employee_id' => 0], 'employee_id'], 'employee array' => ['attendance', ['employee_id' => [1]], 'employee_id'],
            'employee fraction' => ['attendance', ['employee_id' => '1.5'], 'employee_id'], 'page zero' => ['attendance', ['page' => 0], 'page'],
            'page array' => ['attendance', ['page' => [1]], 'page'], 'page size zero' => ['attendance', ['per_page' => 0], 'per_page'],
            'page too large' => ['attendance', ['per_page' => 101], 'per_page'], 'page size array' => ['attendance', ['per_page' => [20]], 'per_page'],
            'invalid status' => ['attendance', ['status' => 'approved'], 'status'], 'array status' => ['attendance', ['status' => ['present']], 'status'],
            'empty status' => ['attendance', ['status' => ''], 'status'], 'snapshot dates' => ['cto-balances', ['start' => '2026-10-01', 'end' => '2026-10-31'], 'start'],
            'snapshot status' => ['leave-balances', ['status' => 'active'], 'status'], 'year below' => ['leave-balances', ['year' => 1899], 'year'],
            'year above' => ['leave-balances', ['year' => 10000], 'year'], 'array year' => ['leave-balances', ['year' => [2026]], 'year'],
            'year unrelated' => ['attendance', ['year' => 2026], 'year'], 'CTO year' => ['cto-balances', ['year' => 2026], 'year'],
            'run zero' => ['payroll', ['payroll_run_id' => 0], 'payroll_run_id'], 'array run' => ['payroll', ['payroll_run_id' => [1]], 'payroll_run_id'],
            'unrelated run' => ['attendance', ['payroll_run_id' => 1], 'payroll_run_id'], 'sort injection' => ['attendance', ['sort' => 'id desc; drop table users'], 'sort'],
            'columns injection' => ['attendance', ['columns' => ['remarks']], 'columns'], 'raw owner' => ['attendance', ['user_id' => 1], 'user_id'],
            'empty unknown' => ['attendance', ['unrecognized' => ''], 'unrecognized'], 'format override' => ['attendance', ['format' => 'xlsx'], 'format'],
        ];
    }

    public function test_requests_enforce_scope_and_export_controls_before_controller(): void
    {
        $actor = $this->user('attendance.view');
        $this->employee($actor);
        $input = ['scope' => 'mine', 'start' => '2026-01-01', 'end' => '2027-01-01', 'per_page' => 100, 'page' => 2];

        $validated = $this->validateRequest(GetReportRequest::class, $input, $actor, ['report' => 'attendance']);
        ksort($input);
        ksort($validated);
        $this->assertSame($input, $validated);
        $this->assertSame([], $this->validateRequest(IndexReportRequest::class, [], $actor));
        $this->assertInvalidRequest(ExportReportRequest::class, ['page' => 1, 'per_page' => 20], $actor, ['report' => 'attendance'], ['page', 'per_page']);
        $this->assertInvalidRequest(IndexReportRequest::class, ['scope' => 'mine'], $actor, fields: ['scope']);
        $this->assertUnauthorizedRequest(GetReportRequest::class, [], $this->user(), ['report' => 'attendance']);
    }

    #[TestWith([['start' => [], 'end' => []]])]
    #[TestWith([['start' => '', 'end' => '']])]
    #[TestWith([['year' => null]])]
    public function test_snapshot_rejects_empty_disallowed_values_without_parsing_them(array $input): void
    {
        $this->assertInvalidRequest(GetReportRequest::class, $input, $this->user('cto.view'), ['report' => 'cto-balances'], array_keys($input));
    }

    public function test_snapshot_endpoint_rejects_empty_array_dates_with_422_and_clear_message(): void
    {
        $response = $this->actingAs($this->user('cto.view'))->json('GET', '/api/v1/reports/cto-balances', ['start' => [], 'end' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['start', 'end']);

        $this->assertSame('The start field is not allowed for this report.', $response->json('errors.start.0'));
    }

    #[TestWith(['page', '1'])]
    #[TestWith(['per_page', '20'])]
    #[TestWith(['page', ''])]
    #[TestWith(['format', 'pdf'])]
    public function test_export_rejects_pagination_and_arbitrary_format(string $field, string $value): void
    {
        $this->actingAs($this->user('attendance.view'))->getJson('/api/v1/reports/attendance/export?'.http_build_query([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_csv_matches_full_filtered_report_and_preserves_decimal_null_and_headers(): void
    {
        $actor = $this->user('leave.view');
        $own = $this->employee($actor);
        $first = $this->record('leave', $own, ['status' => 'approved', 'approved_units' => '1.25']);
        $second = $this->record('leave', $own, ['status' => 'approved', 'approved_units' => null]);
        $this->record('leave', $own, ['status' => 'rejected']);
        $this->record('leave', $this->employee(), ['status' => 'approved']);

        $preview = $this->actingAs($actor)->getJson($this->url('leave', ['status' => 'approved', 'per_page' => 1]))->assertOk()->assertJsonPath('data.summary.records', 2);
        $download = $this->getJson($this->url('leave', ['status' => 'approved'], true))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment;', $download->headers->get('Content-Disposition'));
        $csv = $this->csv($download);
        $this->assertSame(array_column($preview->json('data.report.columns'), 'label'), $csv[0]);
        $this->assertSame([$own->employee_number, $own->employee_number], array_column(array_slice($csv, 1), 0));
        $this->assertNotContains('Record ID', $csv[0]);
        $approvedColumn = array_search('Approved days', $csv[0], true);
        $this->assertSame('1.25', $csv[1][$approvedColumn]);
        $this->assertSame('', $csv[2][$approvedColumn]);
        $this->assertStringNotContainsString('PRIVATE', $download->streamedContent());
    }

    public function test_csv_formula_defense_preserves_unicode_quoting_and_multiline_text(): void
    {
        $actor = $this->user('employees.view');
        $this->employee(null, ['employee_number' => '=1+1', 'first_name' => '@SUM(1,1)', 'last_name' => 'Reyes']);
        $this->employee(null, ['employee_number' => '+2', 'first_name' => " José,\"María\"\nLine ", 'last_name' => 'Santos']);

        $csv = $this->csv($this->actingAs($actor)->getJson($this->url('employees', [], true))->assertOk());
        $this->assertSame("'=1+1", $csv[1][0]);
        $this->assertSame("'@SUM(1,1) Reyes", $csv[1][1]);
        $this->assertSame("'+2", $csv[2][0]);
        $this->assertSame("José,\"María\"\nLine  Santos", $csv[2][1]);
    }

    #[TestWith(['=1+1'])]
    #[TestWith(['+SUM(1,1)'])]
    #[TestWith(['-1+1'])]
    #[TestWith(['@SUM(1,1)'])]
    #[TestWith(["\t=1+1"])]
    #[TestWith(["\r@SUM(1,1)"])]
    #[TestWith(["\x00+1"])]
    #[TestWith(['  =1+1'])]
    public function test_csv_neutralizes_formula_prefixes_hidden_behind_whitespace_or_controls(string $value): void
    {
        $actor = $this->user('employees.view');
        $this->employee(null, ['employee_number' => $value]);

        $csv = $this->csv($this->actingAs($actor)->getJson($this->url('employees', [], true))->assertOk());
        $this->assertSame("'".$value, $csv[1][0]);
    }

    public function test_csv_preserves_numeric_negative_decimal_instead_of_treating_it_as_text_formula(): void
    {
        $actor = $this->user('leave.view');
        $this->record('leave-balances', $this->employee($actor), ['balance' => '-1.25']);

        $csv = $this->csv($this->actingAs($actor)->getJson($this->url('leave-balances', ['year' => 2026], true))->assertOk());
        $this->assertSame('-1.25', $csv[1][array_search('Balance (days)', $csv[0], true)]);
    }

    public function test_export_throttle_is_per_account_and_resets_after_one_minute(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $actor = $this->user('employees.view');
        $other = $this->user('employees.view');
        $url = $this->url('employees', [], true);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->actingAs($actor)->getJson($url)->assertOk();
        }
        $this->getJson($url)->assertTooManyRequests()->assertHeader('Retry-After');
        $this->actingAs($other)->getJson($url)->assertOk();
        $this->travelTo('2026-10-05 10:01:01');
        $this->actingAs($actor)->getJson($url)->assertOk();
    }

    public function test_roster_active_filter_uses_active_flag_and_keeps_safe_organization_labels(): void
    {
        $actor = $this->user('employees.view');
        $wanted = $this->employee(null, ['is_active' => true, 'employment_status' => 'probationary']);
        $this->employee(null, ['is_active' => false, 'employment_status' => 'active']);

        $this->actingAs($actor)->getJson($this->url('employees', ['status' => 'active']))->assertOk()
            ->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.id', $wanted->id)
            ->assertJsonPath('data.rows.0.is_active', true)->assertJsonPath('data.rows.0.position', null);
        $this->getJson($this->url('employees', ['status' => 'inactive']))->assertOk()->assertJsonPath('data.rows.0.is_active', false);
    }

    public function test_catalog_rejects_even_empty_or_unknown_query_parameters(): void
    {
        $response = $this->actingAs($this->user('attendance.view'))->getJson('/api/v1/reports?scope=&unrecognized=1')
            ->assertUnprocessable()->assertJsonValidationErrors(['scope', 'unrecognized']);

        $this->assertSame('Filters are not allowed on the report catalog.', $response->json('errors.scope.0'));
    }

    public function test_export_crosses_chunk_boundaries_without_duplicates_or_truncation(): void
    {
        $actor = $this->user('employees.view');
        $this->insertEmployees(505, 'CHUNK-');

        $csv = $this->csv($this->actingAs($actor)->getJson($this->url('employees', [], true))->assertOk());
        $this->assertCount(506, $csv);
        $this->assertSame('CHUNK-1', $csv[1][0]);
        $this->assertSame('CHUNK-505', $csv[505][0]);
        $this->assertCount(505, array_unique(array_column(array_slice($csv, 1), 0)));
    }

    public function test_export_limit_is_visible_in_preview_and_rejected_before_streaming(): void
    {
        $actor = $this->user('employees.view');
        $this->insertEmployees(10001, 'CAP-');

        $this->actingAs($actor)->getJson($this->url('employees'))->assertOk()->assertJsonPath('data.export.available', false)->assertJsonPath('data.pagination.total', 10001);
        $response = $this->getJson($this->url('employees', [], true))->assertUnprocessable();
        $response->assertJsonValidationErrors('export');
        $this->assertSame('The export is limited to 10000 records. Narrow your filters and try again.', $response->json('errors.export.0'));
    }

    public function test_reports_omit_private_employee_workflow_and_payroll_fields(): void
    {
        $actor = $this->user('employees.view', 'leave.view', 'payroll.view');
        $employee = $this->employee(null, ['personal_email' => 'PRIVATE@example.test', 'address_line_1' => 'PRIVATE ADDRESS', 'birth_date' => '1990-01-01']);
        $this->record('leave', $employee);
        $this->entry($this->payrollRun(), $employee, ['remarks' => 'PRIVATE PAYROLL']);

        foreach (['employees', 'leave', 'payroll'] as $report) {
            $response = $this->actingAs($actor)->getJson($this->url($report))->assertOk();
            $this->assertStringNotContainsString('PRIVATE', $response->getContent());
            foreach ($response->json('data.rows') as $row) {
                foreach (['personal_email', 'birth_date', 'address_line_1', 'reason', 'document_path', 'review_remarks', 'remarks', 'items', 'created_by_user_id'] as $field) {
                    $this->assertArrayNotHasKey($field, $row);
                }
            }
        }
    }

    public function test_many_attendance_employee_labels_use_one_eager_loading_query(): void
    {
        $actor = $this->user('attendance.view', 'employees.view');
        for ($number = 1; $number <= 20; $number++) {
            $this->record('attendance', $this->employee());
        }
        DB::enableQueryLog();

        $this->actingAs($actor)->getJson($this->url('attendance', ['per_page' => 20]))->assertOk()->assertJsonCount(20, 'data.rows');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $labels = array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'from "employees"') && str_contains($query['query'], '"employee_number"'));
        $this->assertCount(1, $labels);
    }

    private function url(string $report, array $filters = [], bool $export = false): string
    {
        if (! in_array($report, ['employees', 'leave-balances', 'cto-balances'], true)) {
            $filters += ['start' => '2026-10-01', 'end' => '2026-10-31'];
        }

        return '/api/v1/reports/'.$report.($export ? '/export' : '').($filters === [] ? '' : '?'.http_build_query($filters));
    }

    private function metric(TestResponse $response, string $label): int|string|null
    {
        foreach ($response->json('data.summary.metrics') as $metric) {
            if ($metric['label'] === $label) {
                return $metric['value'];
            }
        }

        return null;
    }

    /** @return list<list<string|null>> */
    private function csv(TestResponse $response): array
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $response->streamedContent()));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    private function record(string $report, Employee $employee, array $attributes = []): Model
    {
        if ($report === 'employees') {
            return $employee;
        }
        [$model, $fields] = match ($report) {
            'attendance' => [DailyAttendance::class, ['attendance_date' => '2026-10-03', 'status' => 'present', 'worked_minutes' => 480, 'remarks' => 'PRIVATE ATTENDANCE']],
            'leave-balances' => [LeaveBalance::class, ['leave_type_id' => $this->leaveType()->id, 'year' => 2026, 'balance' => '1.25']],
            'cto-balances' => [CtoBalance::class, ['balance_minutes' => 60]],
            'leave' => [LeaveRequest::class, ['leave_type_id' => $this->leaveType()->id, 'start_date' => '2026-10-03', 'end_date' => '2026-10-03', 'requested_units' => '1.00', 'reason' => 'PRIVATE REASON', 'document_path' => 'PRIVATE DOCUMENT', 'status' => 'pending']],
            'cto' => [CtoRequest::class, ['start_at' => '2026-10-03 08:00:00', 'end_at' => '2026-10-03 09:00:00', 'requested_minutes' => 60, 'reason' => 'PRIVATE REASON', 'status' => 'pending']],
            'overtime' => [OvertimeRequest::class, ['start_at' => '2026-10-03 18:00:00', 'end_at' => '2026-10-03 19:00:00', 'requested_minutes' => 60, 'reason' => 'PRIVATE REASON', 'status' => 'pending']],
            'official_business' => [OfficialBusinessRequest::class, ['start_at' => '2026-10-03 08:00:00', 'end_at' => '2026-10-03 09:00:00', 'destination' => 'PRIVATE DESTINATION', 'purpose' => 'PRIVATE PURPOSE', 'status' => 'pending']],
            'travel_order' => [TravelOrderRequest::class, ['start_at' => '2026-10-03 08:00:00', 'end_at' => '2026-10-03 09:00:00', 'destination' => 'PRIVATE DESTINATION', 'purpose' => 'PRIVATE PURPOSE', 'status' => 'pending']],
            'attendance_correction' => [AttendanceCorrectionRequest::class, ['attendance_date' => '2026-10-03', 'requested_time_in' => '2026-10-03 08:00:00', 'requested_time_out' => '2026-10-03 17:00:00', 'reason' => 'PRIVATE REASON', 'status' => 'pending']],
            'profile_changes' => [EmployeeProfileChangeRequest::class, ['reason' => 'PRIVATE REASON', 'status' => 'pending']],
        };

        $record = $model::query()->create(array_replace(['employee_id' => $employee->id], $fields, $attributes));
        $record->forceFill(['created_at' => $attributes['created_at'] ?? '2026-10-03 08:00:00'])->save();

        return $record;
    }

    private function leaveType(): LeaveType
    {
        return LeaveType::query()->create(['code' => 'REPORT-'.Str::uuid(), 'name' => 'Vacation leave', 'requires_balance' => true, 'is_active' => true]);
    }

    private function delegation(User $supervisor, User $delegate, array $attributes = []): ApprovalDelegation
    {
        return ApprovalDelegation::query()->create(array_replace(['delegator_user_id' => $supervisor->id, 'delegate_user_id' => $delegate->id, 'starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-10 23:59:59', 'is_active' => true], $attributes));
    }

    private function payrollRun(array $attributes = [], array $periodAttributes = []): PayrollRun
    {
        $period = PayrollPeriod::query()->create(array_replace(['code' => 'REPORT-'.Str::uuid(), 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'pay_date' => '2026-11-05', 'pay_frequency' => 'monthly', 'status' => 'open'], $periodAttributes));

        return PayrollRun::query()->create(array_replace(['payroll_period_id' => $period->id, 'run_number' => 1, 'status' => 'draft'], $attributes));
    }

    private function entry(PayrollRun $run, Employee $employee, array $attributes = []): PayrollEntry
    {
        return PayrollEntry::query()->create(array_replace(['payroll_run_id' => $run->id, 'employee_id' => $employee->id, 'basic_salary' => '1000.00', 'pay_frequency' => 'monthly', 'currency' => 'PHP', 'gross_pay' => '1000.00', 'total_deductions' => '100.00', 'net_pay' => '900.00', 'status' => 'draft'], $attributes));
    }

    private function insertEmployees(int $count, string $prefix): void
    {
        $rows = [];
        for ($number = 1; $number <= $count; $number++) {
            $rows[] = ['employee_number' => $prefix.$number, 'first_name' => 'Bounded', 'last_name' => 'Employee', 'hire_date' => '2026-01-01', 'employment_type' => 'regular', 'employment_status' => 'active', 'is_active' => true];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            Employee::query()->insert($chunk);
        }
    }
}
