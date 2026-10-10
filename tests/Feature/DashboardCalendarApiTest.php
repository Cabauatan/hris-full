<?php

namespace Tests\Feature;

use App\Models\ApprovalDelegation;
use App\Models\AttendanceCorrectionRequest;
use App\Models\CtoRequest;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\EmployeeProfileChangeRequest;
use App\Models\EmployeeScheduleAssignment;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OfficialBusinessRequest;
use App\Models\OvertimeRequest;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\TravelOrderRequest;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleDay;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\FormRequestTestCase;

class DashboardCalendarApiTest extends FormRequestTestCase
{
    #[TestWith(['dashboard'])]
    #[TestWith(['calendar'])]
    public function test_guest_receives_401(string $endpoint): void
    {
        $this->getJson('/api/v1/'.$endpoint)->assertUnauthorized();
    }

    #[TestWith(['dashboard'])]
    #[TestWith(['calendar'])]
    public function test_inactive_account_receives_403(string $endpoint): void
    {
        $actor = $this->user('holidays.view');
        $actor->update(['is_active' => false]);

        $this->actingAs($actor)->getJson('/api/v1/'.$endpoint)->assertForbidden();
    }

    public function test_permissionless_dashboard_omits_protected_sections(): void
    {
        $actor = $this->user();
        $this->employee($actor);
        $this->attendance($this->employee());
        $this->holiday();

        $this->actingAs($actor)->getJson($this->url('dashboard'))
            ->assertOk()->assertJsonPath('data.workforce', null)->assertJsonPath('data.attendance', null)
            ->assertJsonPath('data.requests', [])->assertJsonPath('data.approvals.total', 0)
            ->assertJsonPath('data.approvals.items', []);
    }

    public function test_permissionless_unlinked_calendar_receives_403(): void
    {
        $actor = $this->user();

        $this->actingAs($actor)->getJson($this->url('calendar'))->assertForbidden();
    }

    #[TestWith(['dashboard'])]
    #[TestWith(['calendar'])]
    public function test_unlinked_account_cannot_request_personal_scope(string $endpoint): void
    {
        $this->actingAs($this->user('holidays.view'))->getJson($this->url($endpoint, ['scope' => 'mine']))->assertForbidden();
    }

    #[TestWith(['dashboard'])]
    #[TestWith(['calendar'])]
    public function test_unlinked_account_reports_only_visible_scope(string $endpoint): void
    {
        $this->actingAs($this->user('holidays.view'))->getJson($this->url($endpoint))
            ->assertOk()->assertJsonPath('data.available_scopes', ['visible'])->assertJsonPath('data.scope', 'visible');
    }

    #[TestWith(['dashboard'])]
    #[TestWith(['calendar'])]
    public function test_default_range_uses_application_month_and_timezone(string $endpoint): void
    {
        $this->travelTo('2026-10-04 12:00:00');
        $actor = $this->user('holidays.view');
        $this->employee($actor);

        $this->actingAs($actor)->getJson('/api/v1/'.$endpoint)
            ->assertOk()->assertJsonPath('data.range.start', '2026-10-01')
            ->assertJsonPath('data.range.end', '2026-10-31')->assertJsonPath('data.range.end_exclusive', '2026-11-01')
            ->assertJsonPath('data.range.timezone', 'Asia/Manila')->assertJsonPath('data.today', '2026-10-04')
            ->assertJsonPath('data.available_scopes', ['mine', 'visible']);
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_or_adversarial_filters_receive_422(string $endpoint, array $query, string $field): void
    {
        $actor = $this->user('holidays.view');
        $this->employee($actor);

        $this->actingAs($actor)->getJson('/api/v1/'.$endpoint.'?'.http_build_query($query))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidQueries(): array
    {
        $cases = [];
        foreach (['dashboard', 'calendar'] as $endpoint) {
            foreach ([
                'forged employee' => [['employee_id' => 987654], 'employee_id'],
                'array employee' => [['employee_id' => [1]], 'employee_id'],
                'unsupported scope' => [['scope' => 'organization'], 'scope'],
                'array scope' => [['scope' => ['visible']], 'scope'],
                'only start' => [['start' => '2026-10-01'], 'end'],
                'only end' => [['end' => '2026-10-31'], 'start'],
                'invalid leap day' => [['start' => '2026-02-29', 'end' => '2026-03-01'], 'start'],
                'non canonical date' => [['start' => '2026-1-1', 'end' => '2026-01-31'], 'start'],
                'reversed dates' => [['start' => '2026-10-05', 'end' => '2026-10-04'], 'end'],
                'array date' => [['start' => ['2026-10-01'], 'end' => '2026-10-31'], 'start'],
                'empty start' => [['start' => '', 'end' => '2026-10-31'], 'start'],
                'empty end' => [['start' => '2026-10-01', 'end' => ''], 'end'],
                'array end' => [['start' => '2026-10-01', 'end' => ['2026-10-31']], 'end'],
            ] as $name => [$query, $field]) {
                $cases[$endpoint.' / '.$name] = [$endpoint, $query, $field];
            }
        }
        $cases['dashboard / 367 days'] = ['dashboard', ['start' => '2026-01-01', 'end' => '2027-01-02'], 'end'];
        $cases['calendar / 63 days'] = ['calendar', ['start' => '2026-10-01', 'end' => '2026-12-02'], 'end'];
        $cases['calendar / invalid type'] = ['calendar', ['types' => ['payroll']], 'types.0'];
        $cases['calendar / scalar types'] = ['calendar', ['types' => 'holiday'], 'types'];
        $cases['calendar / associative types'] = ['calendar', ['types' => ['holiday' => 'holiday']], 'types'];

        return $cases;
    }

    #[TestWith(['dashboard', '2026-01-01', '2027-01-01'])]
    #[TestWith(['calendar', '2026-10-01', '2026-12-01'])]
    public function test_maximum_inclusive_window_is_accepted(string $endpoint, string $start, string $end): void
    {
        $this->actingAs($this->user('holidays.view'))->getJson('/api/v1/'.$endpoint.'?'.http_build_query(compact('start', 'end')))
            ->assertOk()->assertJsonPath('data.range.start', $start)->assertJsonPath('data.range.end', $end);
    }

    public function test_unauthorized_type_receives_403_even_if_another_type_is_allowed(): void
    {
        $actor = $this->user('holidays.view');
        $this->employee($actor);

        $this->actingAs($actor)->getJson($this->url('calendar', ['types' => ['leave']]))->assertForbidden();
    }

    public function test_attendance_counts_only_own_and_direct_team_processed_records(): void
    {
        $actor = $this->user('attendance.view', 'employees.view-team');
        $own = $this->employee($actor);
        $report = $this->employee(null, ['supervisor_employee_id' => $own->id]);
        $grandReport = $this->employee(null, ['supervisor_employee_id' => $report->id]);
        $this->attendance($own, ['status' => 'present', 'late_minutes' => 4, 'overtime_minutes' => 5]);
        $this->attendance($report, ['status' => 'incomplete', 'undertime_minutes' => 9]);
        $this->attendance($grandReport, ['status' => 'absent', 'late_minutes' => 100]);
        $this->attendance($this->employee(), ['status' => 'absent', 'late_minutes' => 200]);
        $this->attendance($own, ['attendance_date' => '2026-09-30', 'status' => 'absent']);

        $this->actingAs($actor)->getJson($this->url('dashboard', ['scope' => 'visible']))
            ->assertOk()->assertJsonPath('data.attendance.total', 2)
            ->assertJsonPath('data.attendance.by_status.present', 1)->assertJsonPath('data.attendance.by_status.incomplete', 1)
            ->assertJsonPath('data.attendance.late_minutes', 4)->assertJsonPath('data.attendance.undertime_minutes', 9)
            ->assertJsonPath('data.attendance.overtime_minutes', 5)->assertJsonPath('data.workforce.total', 2);
    }

    public function test_personal_scope_remains_own_only_for_organization_administrator(): void
    {
        $actor = $this->user('attendance.view', 'employees.view', 'leave.view', 'schedules.assign');
        $own = $this->employee($actor);
        $report = $this->employee(null, ['supervisor_employee_id' => $own->id]);
        $this->attendance($own, ['late_minutes' => 3]);
        $this->attendance($report, ['late_minutes' => 500]);
        $wanted = $this->requestRecord('leave', $own);
        $this->requestRecord('leave', $report);

        $response = $this->actingAs($actor)->getJson($this->url('dashboard', ['scope' => 'mine']))
            ->assertOk()->assertJsonPath('data.attendance.total', 1)->assertJsonPath('data.attendance.late_minutes', 3);
        $this->assertSame(1, $this->summary($response->json('data.requests'), 'leave')['total']);
        $calendar = $this->getJson($this->url('calendar', ['scope' => 'mine', 'types' => ['leave']]))->assertOk();
        $this->assertSame([$wanted->id], array_column(array_column($calendar->json('data.events'), 'source'), 'id'));
    }

    public function test_module_permission_is_required_even_with_global_employee_access(): void
    {
        $actor = $this->user('employees.view', 'holidays.view');
        $own = $this->employee($actor);
        $this->attendance($own);
        $this->requestRecord('leave', $own);

        $this->actingAs($actor)->getJson($this->url('dashboard', ['scope' => 'visible']))
            ->assertOk()->assertJsonPath('data.attendance', null)->assertJsonPath('data.requests', []);
        $this->getJson($this->url('calendar', ['scope' => 'visible']))
            ->assertOk()->assertJsonPath('data.allowed_types', ['birthday', 'holiday'])->assertJsonPath('data.events', []);
    }

    public function test_missing_processed_attendance_does_not_invent_absence(): void
    {
        $actor = $this->user('attendance.view', 'employees.view');
        $this->employee($actor);
        $this->employee();

        $this->actingAs($actor)->getJson($this->url('dashboard', ['scope' => 'visible']))
            ->assertOk()->assertJsonPath('data.attendance.total', 0)->assertJsonPath('data.attendance.by_status', [])
            ->assertJsonPath('data.attendance.late_minutes', 0);
    }

    #[DataProvider('supervisedTypes')]
    public function test_request_aggregates_and_calendar_obey_direct_team_visibility(string $type, string $prefix): void
    {
        $actor = $this->user($prefix.'.view', 'employees.view-team');
        $own = $this->employee($actor);
        $report = $this->employee(null, ['supervisor_employee_id' => $own->id]);
        $grandReport = $this->employee(null, ['supervisor_employee_id' => $report->id]);
        $this->requestRecord($type, $own);
        $wanted = $this->requestRecord($type, $report);
        $this->requestRecord($type, $grandReport);
        $this->requestRecord($type, $this->employee());

        $response = $this->actingAs($actor)->getJson($this->url('dashboard', ['scope' => 'visible']))->assertOk();
        $this->assertSame(2, $this->summary($response->json('data.requests'), $type)['total']);
        $this->assertSame(0, $response->json('data.approvals.total'));
        $calendar = $this->getJson($this->url('calendar', ['scope' => 'visible', 'types' => [$type]]))->assertOk();
        $this->assertCount(2, $calendar->json('data.events'));
        $this->assertContains($wanted->id, array_column(array_column($calendar->json('data.events'), 'source'), 'id'));
        $employeeIds = array_column(array_column($calendar->json('data.events'), 'employee'), 'id');
        sort($employeeIds);
        $this->assertSame([$own->id, $report->id], $employeeIds);
    }

    public static function supervisedTypes(): array
    {
        return [
            'leave' => ['leave', 'leave'], 'cto' => ['cto', 'cto'], 'overtime' => ['overtime', 'overtime'],
            'official business' => ['official_business', 'official-business'], 'travel order' => ['travel_order', 'travel-orders'],
        ];
    }

    public function test_pending_approval_count_is_exact_beyond_preview_and_ignores_date_range(): void
    {
        $supervisor = $this->user('cto.view', 'cto.approve', 'employees.view-team');
        $report = $this->employee(null, ['supervisor_employee_id' => $this->employee($supervisor)->id]);
        for ($index = 0; $index < 13; $index++) {
            $this->requestRecord('cto', $report, ['status' => 'pending', 'start_at' => '2026-01-02 08:00:00', 'end_at' => '2026-01-02 09:00:00']);
        }
        $this->requestRecord('cto', $report);
        $this->requestRecord('cto', $this->employee(), ['status' => 'pending']);

        $response = $this->actingAs($supervisor)->getJson($this->url('dashboard', ['scope' => 'visible']))
            ->assertOk()->assertJsonPath('data.approvals.total', 13)->assertJsonCount(10, 'data.approvals.items');
        $this->assertSame(13, $this->summary($response->json('data.approvals.by_type'), 'cto')['total']);
        $this->assertSame(1, $this->summary($response->json('data.requests'), 'cto')['total']);
        foreach ($response->json('data.approvals.items') as $item) {
            $this->assertSame($report->id, $item['employee']['id']);
            $this->assertSame('pending', $item['status']);
            $this->assertSafeDto($item);
        }
    }

    public function test_organization_read_access_does_not_grant_unrelated_approval_authority(): void
    {
        $hr = $this->user('employees.view', 'cto.view', 'cto.approve');
        $supervisor = $this->user('cto.view', 'cto.approve');
        $report = $this->employee(null, ['supervisor_employee_id' => $this->employee($supervisor)->id]);
        $this->requestRecord('cto', $report, ['status' => 'pending']);

        $response = $this->actingAs($hr)->getJson($this->url('dashboard', ['scope' => 'visible']))
            ->assertOk()->assertJsonPath('data.approvals.total', 0)->assertJsonPath('data.approvals.items', []);
        $this->assertSame(1, $this->summary($response->json('data.requests'), 'cto')['total']);
    }

    public function test_current_unlinked_delegate_can_review_without_reading_delegated_attendance(): void
    {
        [$supervisor, $delegate, $report, $delegation] = $this->delegatedFixture();
        $approved = $this->requestRecord('cto', $report);
        $pending = $this->requestRecord('cto', $report, ['status' => 'pending']);
        $this->attendance($report, ['late_minutes' => 123]);

        $response = $this->actingAs($delegate)->getJson($this->url('dashboard', ['scope' => 'visible']))
            ->assertOk()->assertJsonPath('data.approvals.total', 1)->assertJsonPath('data.approvals.items.0.id', $pending->id)
            ->assertJsonPath('data.attendance.total', 0)->assertJsonPath('data.workforce', null);
        $this->assertSame(2, $this->summary($response->json('data.requests'), 'cto')['total']);
        $this->getJson($this->url('calendar', ['scope' => 'visible', 'types' => ['cto']]))
            ->assertOk()->assertJsonCount(1, 'data.events')->assertJsonPath('data.events.0.source.id', $approved->id);
    }

    public function test_original_supervisor_retains_team_read_but_has_no_approvals_while_delegated(): void
    {
        [$supervisor, $delegate, $report] = $this->delegatedFixture();
        $this->requestRecord('cto', $report, ['status' => 'pending']);

        $response = $this->actingAs($supervisor)->getJson($this->url('dashboard', ['scope' => 'visible']))
            ->assertOk()->assertJsonPath('data.approvals.total', 0);
        $this->assertSame(1, $this->summary($response->json('data.requests'), 'cto')['total']);
    }

    #[DataProvider('ineffectiveDelegations')]
    public function test_invalid_delegation_does_not_expose_outside_requests(string $condition): void
    {
        [$supervisor, $delegate, $report, $delegation] = $this->delegatedFixture();
        $this->requestRecord('cto', $report);
        $this->requestRecord('cto', $report, ['status' => 'pending']);
        match ($condition) {
            'expired' => $delegation->update(['ends_at' => now()->subSecond()]),
            'future' => $delegation->update(['starts_at' => now()->addSecond()]),
            'inactive' => $delegation->update(['is_active' => false]),
            'inactive delegator' => $supervisor->update(['is_active' => false]),
            'unqualified delegator' => $supervisor->permissions()->detach(Permission::query()->where('code', 'cto.approve')->sole()),
            'unqualified delegate' => $delegate->permissions()->detach(Permission::query()->where('code', 'cto.approve')->sole()),
            'inactive permission' => Permission::query()->where('code', 'cto.approve')->sole()->update(['is_active' => false]),
            'latest other delegate' => $this->delegation($supervisor, $this->user('cto.view', 'cto.approve'), ['starts_at' => now()]),
            'latest unqualified delegate' => $this->delegation($supervisor, $this->user('cto.view'), ['starts_at' => now()]),
        };

        $response = $this->actingAs($delegate->fresh())->getJson($this->url('dashboard', ['scope' => 'visible']))
            ->assertOk()->assertJsonPath('data.approvals.total', 0);
        $this->assertSame(0, $this->summary($response->json('data.requests'), 'cto')['total']);
        $this->getJson($this->url('calendar', ['scope' => 'visible', 'types' => ['cto']]))
            ->assertOk()->assertJsonPath('data.events', []);
    }

    public static function ineffectiveDelegations(): array
    {
        $conditions = ['expired', 'future', 'inactive', 'inactive delegator', 'unqualified delegator', 'unqualified delegate', 'inactive permission', 'latest other delegate', 'latest unqualified delegate'];

        return array_combine($conditions, array_map(static fn (string $condition): array => [$condition], $conditions));
    }

    public function test_delegation_revocation_takes_effect_in_both_aggregates(): void
    {
        [$supervisor, $delegate, $report, $delegation] = $this->delegatedFixture();
        $this->requestRecord('cto', $report);
        $this->requestRecord('cto', $report, ['status' => 'pending']);
        $this->actingAs($delegate)->getJson($this->url('dashboard', ['scope' => 'visible']))
            ->assertOk()->assertJsonPath('data.approvals.total', 1);
        $this->getJson($this->url('calendar', ['scope' => 'visible', 'types' => ['cto']]))->assertOk()->assertJsonCount(1, 'data.events');
        $delegation->update(['is_active' => false]);

        $this->getJson($this->url('dashboard', ['scope' => 'visible']))->assertOk()->assertJsonPath('data.approvals.total', 0);
        $this->getJson($this->url('calendar', ['scope' => 'visible', 'types' => ['cto']]))->assertOk()->assertJsonPath('data.events', []);
    }

    public function test_profile_approval_queue_intersects_profile_read_visibility(): void
    {
        $actor = $this->user('profile-changes.view', 'profile-changes.approve');
        $own = $this->employee($actor);
        $this->requestRecord('profile_changes', $own, ['status' => 'pending']);
        $this->requestRecord('profile_changes', $this->employee(), ['status' => 'pending']);
        $this->requestRecord('profile_changes', $own, ['status' => 'approved']);

        $this->actingAs($actor)->getJson($this->url('dashboard', ['scope' => 'visible']))
            ->assertOk()->assertJsonPath('data.approvals.total', 1)->assertJsonCount(1, 'data.approvals.items')
            ->assertJsonPath('data.approvals.items.0.employee.id', $own->id);
    }

    public function test_active_holiday_is_global_date_bounded_with_exclusive_end(): void
    {
        $actor = $this->user('holidays.view');
        $this->employee($actor);
        $wanted = $this->holiday();
        $this->holiday(['holiday_date' => '2026-10-05', 'is_active' => false]);
        $this->holiday(['holiday_date' => '2026-09-30']);

        $this->actingAs($actor)->getJson($this->url('calendar', ['types' => ['holiday'], 'scope' => 'mine']))
            ->assertOk()->assertJsonCount(1, 'data.events')->assertJsonPath('data.events.0.start', '2026-10-04')
            ->assertJsonPath('data.events.0.end', '2026-10-05')->assertJsonPath('data.events.0.all_day', true)
            ->assertJsonPath('data.events.0.employee', null)->assertJsonPath('data.events.0.source.id', $wanted->id);
    }

    public function test_calendar_only_includes_approved_leave_overlapping_inclusive_window(): void
    {
        $actor = $this->user('leave.view');
        $own = $this->employee($actor);
        $wanted = $this->requestRecord('leave', $own, ['start_date' => '2026-09-30', 'end_date' => '2026-10-01']);
        foreach (['pending', 'rejected', 'cancelled'] as $status) {
            $this->requestRecord('leave', $own, compact('status'));
        }
        $this->requestRecord('leave', $own, ['start_date' => '2026-09-29', 'end_date' => '2026-09-30']);
        $this->requestRecord('leave', $own, ['start_date' => '2026-11-01', 'end_date' => '2026-11-02']);

        $response = $this->actingAs($actor)->getJson($this->url('calendar', ['types' => ['leave'], 'scope' => 'mine']))
            ->assertOk()->assertJsonCount(1, 'data.events')->assertJsonPath('data.events.0.source.id', $wanted->id)
            ->assertJsonPath('data.events.0.start', '2026-09-30')->assertJsonPath('data.events.0.end', '2026-10-02')
            ->assertJsonPath('data.events.0.all_day', true)->assertJsonPath('data.events.0.status', 'approved');
        $this->assertSafeDto($response->json('data.events.0'));
    }

    #[DataProvider('timedTypes')]
    public function test_timed_events_use_strict_overlap_and_keep_application_offsets(string $type, string $prefix): void
    {
        $actor = $this->user($prefix.'.view');
        $own = $this->employee($actor);
        $wanted = $this->requestRecord($type, $own, ['start_at' => '2026-09-30 23:30:00', 'end_at' => '2026-10-01 00:30:00']);
        $this->requestRecord($type, $own, ['start_at' => '2026-09-30 23:00:00', 'end_at' => '2026-10-01 00:00:00']);
        $this->requestRecord($type, $own, ['start_at' => '2026-11-01 00:00:00', 'end_at' => '2026-11-01 01:00:00']);
        $this->requestRecord($type, $own, ['status' => 'pending']);

        $response = $this->actingAs($actor)->getJson($this->url('calendar', ['types' => [$type], 'scope' => 'mine']))
            ->assertOk()->assertJsonCount(1, 'data.events')->assertJsonPath('data.events.0.source.id', $wanted->id)
            ->assertJsonPath('data.events.0.start', '2026-09-30T23:30:00+08:00')
            ->assertJsonPath('data.events.0.end', '2026-10-01T00:30:00+08:00')->assertJsonPath('data.events.0.all_day', false);
        $this->assertSafeDto($response->json('data.events.0'));
    }

    public static function timedTypes(): array
    {
        $types = self::supervisedTypes();
        unset($types['leave']);

        return $types;
    }

    public function test_global_employee_permission_does_not_broaden_schedule_visibility_without_assign_permission(): void
    {
        $actor = $this->user('schedules.view', 'employees.view');
        $own = $this->employee($actor);
        $this->assignment($own);
        $this->assignment($this->employee());

        $this->actingAs($actor)->getJson($this->url('calendar', ['types' => ['schedule'], 'scope' => 'visible', 'start' => '2026-10-05', 'end' => '2026-10-05']))
            ->assertOk()->assertJsonCount(1, 'data.events')->assertJsonPath('data.events.0.employee.id', $own->id);
    }

    public function test_assignment_permission_allows_organization_schedule_visibility(): void
    {
        $actor = $this->user('schedules.assign');
        $this->assignment($this->employee());
        $this->assignment($this->employee());

        $this->actingAs($actor)->getJson($this->url('calendar', ['types' => ['schedule'], 'scope' => 'visible', 'start' => '2026-10-05', 'end' => '2026-10-05']))
            ->assertOk()->assertJsonCount(2, 'data.events');
    }

    public function test_schedule_uses_latest_effective_assignment_and_id_skipping_inactive_schedule(): void
    {
        $actor = $this->user('schedules.view');
        $own = $this->employee($actor);
        $this->assignment($own, ['effective_from' => '2026-09-01']);
        $this->assignment($own, ['effective_from' => '2026-10-05']);
        $wanted = $this->assignment($own, ['effective_from' => '2026-10-05']);
        $inactive = $this->assignment($own, ['effective_from' => '2026-10-05']);
        $inactive->workSchedule->update(['is_active' => false]);
        $this->assignment($own, ['effective_from' => '2026-10-06']);

        $this->actingAs($actor)->getJson($this->url('calendar', ['types' => ['schedule'], 'scope' => 'mine', 'start' => '2026-10-05', 'end' => '2026-10-05']))
            ->assertOk()->assertJsonCount(1, 'data.events')->assertJsonPath('data.events.0.source.id', $wanted->id);
    }

    public function test_inclusive_assignment_end_and_overnight_shift_are_preserved(): void
    {
        $actor = $this->user('schedules.view');
        $own = $this->employee($actor);
        $assignment = $this->assignment($own, ['effective_from' => '2026-10-05', 'effective_to' => '2026-10-05'], ['start_time' => '22:00:00', 'end_time' => '06:00:00', 'is_overnight' => true]);

        $this->actingAs($actor)->getJson($this->url('calendar', ['types' => ['schedule'], 'scope' => 'mine', 'start' => '2026-10-05', 'end' => '2026-10-06']))
            ->assertOk()->assertJsonCount(1, 'data.events')->assertJsonPath('data.events.0.source.id', $assignment->id)
            ->assertJsonPath('data.events.0.start', '2026-10-05T22:00:00+08:00')
            ->assertJsonPath('data.events.0.end', '2026-10-06T06:00:00+08:00');
    }

    public function test_missing_schedule_day_warns_without_breaking_holidays(): void
    {
        $actor = $this->user('schedules.view', 'holidays.view');
        $own = $this->employee($actor);
        $this->assignment($own)->workSchedule->days()->delete();
        $this->holiday(['holiday_date' => '2026-10-05']);

        $response = $this->actingAs($actor)->getJson($this->url('calendar', ['types' => ['schedule', 'holiday'], 'scope' => 'mine', 'start' => '2026-10-05', 'end' => '2026-10-05']))
            ->assertOk()->assertJsonCount(1, 'data.events')->assertJsonPath('data.events.0.type', 'holiday');
        $this->assertNotEmpty($response->json('data.meta.warnings'));
    }

    public function test_calendar_marks_results_truncated_at_event_cap(): void
    {
        $actor = $this->user('holidays.view');
        $holidays = [];
        for ($index = 0; $index < 501; $index++) {
            $holidays[] = ['name' => 'Holiday '.$index, 'holiday_date' => '2026-10-04', 'holiday_type' => 'regular', 'is_active' => true, 'is_working_day' => false, 'is_paid' => true];
        }
        Holiday::query()->insert($holidays);

        $this->actingAs($actor)->getJson($this->url('calendar', ['types' => ['holiday'], 'scope' => 'visible']))
            ->assertOk()->assertJsonCount(500, 'data.events')->assertJsonPath('data.meta.truncated', true);
    }

    public function test_explicit_empty_type_selection_returns_no_events(): void
    {
        $actor = $this->user('holidays.view');
        $this->holiday();

        $this->actingAs($actor)->getJson($this->url('calendar').'&types=')
            ->assertOk()->assertJsonPath('data.allowed_types', ['holiday'])
            ->assertJsonPath('data.events', [])->assertJsonPath('data.meta.truncated', false);
    }

    public function test_rest_day_is_all_day_without_fabricated_shift(): void
    {
        $actor = $this->user('schedules.view');
        $own = $this->employee($actor);
        $assignment = $this->assignment($own, ['effective_from' => '2026-10-05']);
        $assignment->workSchedule->days()->where('day_of_week', 1)->update(['is_working_day' => false, 'shift_id' => null]);

        $this->actingAs($actor)->getJson($this->url('calendar', ['types' => ['schedule'], 'scope' => 'mine', 'start' => '2026-10-05', 'end' => '2026-10-05']))
            ->assertOk()->assertJsonCount(1, 'data.events')->assertJsonPath('data.events.0.status', 'rest_day')
            ->assertJsonPath('data.events.0.start', '2026-10-05')->assertJsonPath('data.events.0.end', '2026-10-06')
            ->assertJsonPath('data.events.0.all_day', true)->assertJsonPath('data.events.0.details.shift_id', null);
    }

    public function test_previous_day_overnight_shift_overlapping_start_is_included(): void
    {
        $actor = $this->user('schedules.view');
        $own = $this->employee($actor);
        $assignment = $this->assignment($own, ['effective_from' => '2026-10-04', 'effective_to' => '2026-10-04'], ['start_time' => '22:00:00', 'end_time' => '06:00:00', 'is_overnight' => true]);

        $this->actingAs($actor)->getJson($this->url('calendar', ['types' => ['schedule'], 'scope' => 'mine', 'start' => '2026-10-05', 'end' => '2026-10-05']))
            ->assertOk()->assertJsonCount(1, 'data.events')->assertJsonPath('data.events.0.source.id', $assignment->id)
            ->assertJsonPath('data.events.0.start', '2026-10-04T22:00:00+08:00')
            ->assertJsonPath('data.events.0.end', '2026-10-05T06:00:00+08:00');
    }

    public function test_working_holiday_does_not_remove_shift(): void
    {
        $actor = $this->user('schedules.view', 'holidays.view');
        $own = $this->employee($actor);
        $this->assignment($own, ['effective_from' => '2026-10-05']);
        $this->holiday(['holiday_date' => '2026-10-05', 'is_working_day' => true]);

        $response = $this->actingAs($actor)->getJson($this->url('calendar', ['types' => ['schedule', 'holiday'], 'scope' => 'mine', 'start' => '2026-10-05', 'end' => '2026-10-05']))
            ->assertOk()->assertJsonCount(2, 'data.events');
        $eventTypes = array_column($response->json('data.events'), 'type');
        sort($eventTypes);
        $this->assertSame(['holiday', 'schedule'], $eventTypes);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_invalid_working_shift_is_skipped_with_warning(bool $missing): void
    {
        $actor = $this->user('schedules.view');
        $own = $this->employee($actor);
        $assignment = $this->assignment($own, ['effective_from' => '2026-10-05']);
        $day = $assignment->workSchedule->days()->where('day_of_week', 1)->sole();
        if ($missing) {
            $day->update(['shift_id' => null]);
        } else {
            $day->shift->update(['is_active' => false]);
        }

        $response = $this->actingAs($actor)->getJson($this->url('calendar', ['types' => ['schedule'], 'scope' => 'mine', 'start' => '2026-10-05', 'end' => '2026-10-05']))
            ->assertOk()->assertJsonPath('data.events', []);
        $this->assertNotEmpty($response->json('data.meta.warnings'));
    }

    public function test_calendar_at_exact_cap_is_not_truncated(): void
    {
        $actor = $this->user('holidays.view');
        $holidays = [];
        for ($index = 0; $index < 500; $index++) {
            $holidays[] = ['name' => 'Holiday '.$index, 'holiday_date' => '2026-10-04', 'holiday_type' => 'regular', 'is_active' => true, 'is_working_day' => false, 'is_paid' => true];
        }
        Holiday::query()->insert($holidays);

        $this->actingAs($actor)->getJson($this->url('calendar', ['types' => ['holiday'], 'scope' => 'visible']))
            ->assertOk()->assertJsonCount(500, 'data.events')->assertJsonPath('data.meta.truncated', false);
    }

    public function test_global_attendance_scope_includes_organization_and_workforce_active_totals(): void
    {
        $actor = $this->user('employees.view', 'attendance.view');
        $this->attendance($this->employee($actor));
        $this->attendance($this->employee(null, ['is_active' => false]), ['status' => 'absent']);
        $this->attendance($this->employee(), ['status' => 'incomplete']);

        $this->actingAs($actor)->getJson($this->url('dashboard', ['scope' => 'visible']))
            ->assertOk()->assertJsonPath('data.workforce.total', 3)->assertJsonPath('data.workforce.active', 2)
            ->assertJsonPath('data.workforce.inactive', 1)->assertJsonPath('data.attendance.total', 3);
    }

    public function test_approval_permission_without_view_permission_does_not_expose_queue(): void
    {
        $actor = $this->user('cto.approve', 'employees.view-team');
        $own = $this->employee($actor);
        $this->requestRecord('cto', $this->employee(null, ['supervisor_employee_id' => $own->id]), ['status' => 'pending']);

        $this->actingAs($actor)->getJson($this->url('dashboard', ['scope' => 'visible']))
            ->assertOk()->assertJsonPath('data.requests', [])->assertJsonPath('data.approvals.total', 0)
            ->assertJsonPath('data.approvals.items', []);
    }

    #[DataProvider('reviewTypes')]
    public function test_every_supervised_workflow_respects_effective_delegate_for_pending_queue(string $type, string $prefix): void
    {
        $this->travelTo('2026-10-04 12:00:00');
        $supervisor = $this->user($prefix.'.view', $prefix.'.approve', 'employees.view-team');
        $delegate = $this->user($prefix.'.view', $prefix.'.approve');
        $report = $this->employee(null, ['supervisor_employee_id' => $this->employee($supervisor)->id]);
        $wanted = $this->requestRecord($type, $report, ['status' => 'pending']);
        $this->requestRecord($type, $this->employee(), ['status' => 'pending']);
        $this->delegation($supervisor, $delegate, ['starts_at' => now(), 'ends_at' => now()]);

        $this->actingAs($delegate)->getJson($this->url('dashboard', ['scope' => 'visible']))
            ->assertOk()->assertJsonPath('data.approvals.total', 1)
            ->assertJsonPath('data.approvals.items.0.id', $wanted->id)
            ->assertJsonPath('data.approvals.items.0.type', $type);
        $this->travel(1)->seconds();
        $this->getJson($this->url('dashboard', ['scope' => 'visible']))
            ->assertOk()->assertJsonPath('data.approvals.total', 0);
    }

    public static function reviewTypes(): array
    {
        return self::supervisedTypes() + ['attendance correction' => ['attendance_correction', 'attendance-corrections']];
    }

    public function test_employee_visible_scope_still_excludes_other_employees(): void
    {
        $actor = $this->user('attendance.view', 'leave.view');
        $own = $this->employee($actor);
        $this->attendance($own);
        $this->attendance($this->employee(), ['late_minutes' => 600]);
        $wanted = $this->requestRecord('leave', $own);
        $this->requestRecord('leave', $this->employee());

        $this->actingAs($actor)->getJson($this->url('dashboard', ['scope' => 'visible']))
            ->assertOk()->assertJsonPath('data.attendance.total', 1)->assertJsonPath('data.attendance.late_minutes', 0)
            ->assertJsonPath('data.workforce', null);
        $this->getJson($this->url('calendar', ['scope' => 'visible', 'types' => ['leave']]))
            ->assertOk()->assertJsonCount(1, 'data.events')->assertJsonPath('data.events.0.source.id', $wanted->id);
    }

    private function url(string $endpoint, array $query = []): string
    {
        return '/api/v1/'.$endpoint.'?'.http_build_query(array_replace(['start' => '2026-10-01', 'end' => '2026-10-31'], $query));
    }

    private function summary(array $summaries, string $type): array
    {
        $summary = collect($summaries)->firstWhere('type', $type);
        $this->assertIsArray($summary, 'Missing summary for '.$type.'.');

        return $summary;
    }

    private function attendance(Employee $employee, array $attributes = []): DailyAttendance
    {
        return DailyAttendance::query()->create(array_replace([
            'employee_id' => $employee->id, 'attendance_date' => '2026-10-04', 'status' => 'present',
            'late_minutes' => 0, 'undertime_minutes' => 0, 'overtime_minutes' => 0,
        ], $attributes));
    }

    private function holiday(array $attributes = []): Holiday
    {
        return Holiday::query()->create(array_replace([
            'name' => 'Test holiday', 'holiday_date' => '2026-10-04', 'holiday_type' => 'regular',
            'is_working_day' => false, 'is_paid' => true, 'is_active' => true,
        ], $attributes));
    }

    private function requestRecord(string $type, Employee $employee, array $attributes = []): Model
    {
        [$model, $fields] = match ($type) {
            'leave' => [LeaveRequest::class, [
                'leave_type_id' => LeaveType::query()->create(['code' => (string) Str::uuid(), 'name' => 'Vacation', 'requires_balance' => false, 'is_active' => true])->id,
                'start_date' => '2026-10-04', 'end_date' => '2026-10-04', 'day_portion' => 'full_day',
                'requested_units' => '1.00', 'approved_units' => '1.00', 'reason' => 'Private request reason',
            ]],
            'cto' => [CtoRequest::class, ['start_at' => '2026-10-04 08:00:00', 'end_at' => '2026-10-04 09:00:00', 'requested_minutes' => 60, 'approved_minutes' => 60, 'reason' => 'Private request reason']],
            'overtime' => [OvertimeRequest::class, ['start_at' => '2026-10-04 18:00:00', 'end_at' => '2026-10-04 19:00:00', 'requested_minutes' => 60, 'approved_minutes' => 60, 'reason' => 'Private request reason']],
            'official_business' => [OfficialBusinessRequest::class, ['start_at' => '2026-10-04 08:00:00', 'end_at' => '2026-10-04 09:00:00', 'purpose' => 'Private official purpose']],
            'travel_order' => [TravelOrderRequest::class, ['start_at' => '2026-10-04 08:00:00', 'end_at' => '2026-10-04 09:00:00', 'destination' => 'Head office', 'purpose' => 'Private travel purpose']],
            'attendance_correction' => [AttendanceCorrectionRequest::class, ['attendance_date' => '2026-10-04', 'requested_time_in' => '2026-10-04 08:00:00', 'reason' => 'Private request reason']],
            'profile_changes' => [EmployeeProfileChangeRequest::class, ['reason' => 'Private profile change']],
        };

        return $model::query()->create(array_replace($fields, [
            'employee_id' => $employee->id, 'created_by_user_id' => $employee->user_id,
            'status' => 'approved', 'review_remarks' => 'Private review remarks',
        ], $attributes));
    }

    /** @return array{User, User, Employee, ApprovalDelegation} */
    private function delegatedFixture(): array
    {
        $this->travelTo('2026-10-04 12:00:00');
        $supervisor = $this->user('cto.view', 'cto.approve', 'employees.view-team');
        $delegate = $this->user('cto.view', 'cto.approve', 'attendance.view', 'schedules.view');
        $report = $this->employee(null, ['supervisor_employee_id' => $this->employee($supervisor)->id]);

        return [$supervisor, $delegate, $report, $this->delegation($supervisor, $delegate)];
    }

    private function delegation(User $supervisor, User $delegate, array $attributes = []): ApprovalDelegation
    {
        return ApprovalDelegation::query()->create(array_replace([
            'delegator_user_id' => $supervisor->id, 'delegate_user_id' => $delegate->id,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'is_active' => true,
        ], $attributes));
    }

    private function assignment(Employee $employee, array $attributes = [], array $shiftAttributes = []): EmployeeScheduleAssignment
    {
        $schedule = WorkSchedule::query()->create(['code' => (string) Str::uuid(), 'name' => 'Test schedule', 'is_active' => true]);
        $shift = Shift::query()->create(array_replace([
            'code' => (string) Str::uuid(), 'name' => 'Test shift', 'start_time' => '08:00:00',
            'end_time' => '17:00:00', 'is_active' => true, 'is_overnight' => false,
        ], $shiftAttributes));
        foreach (range(1, 7) as $weekday) {
            WorkScheduleDay::query()->create(['work_schedule_id' => $schedule->id, 'day_of_week' => $weekday, 'is_working_day' => true, 'shift_id' => $shift->id]);
        }

        return EmployeeScheduleAssignment::query()->create(array_replace([
            'employee_id' => $employee->id, 'work_schedule_id' => $schedule->id, 'effective_from' => '2026-10-01', 'effective_to' => null,
        ], $attributes));
    }

    private function assertSafeDto(array $dto): void
    {
        foreach (['reason', 'purpose', 'document_path', 'review_remarks', 'cancellation_reason', 'password', 'personal_email', 'birth_date', 'file_path', 'net_pay'] as $key) {
            $this->assertArrayNotHasKey($key, $dto);
        }
        foreach ($dto as $value) {
            if (is_array($value)) {
                $this->assertSafeDto($value);
            }
        }
    }
}
