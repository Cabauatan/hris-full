<?php

namespace Tests\Feature;

use App\Http\Requests\GetCalendarRequest;
use App\Http\Requests\GetDashboardRequest;
use App\Models\CtoRequest;
use App\Models\DailyAttendance;
use App\Models\Holiday;
use App\Policies\WorkspacePolicy;
use App\Services\Workspace\CalendarService;
use App\Services\Workspace\DashboardService;
use Tests\FormRequestTestCase;

class WorkspaceServiceTest extends FormRequestTestCase
{
    public function test_attendance_with_unclassified_status_remains_counted_as_unknown(): void
    {
        $actor = $this->user('attendance.view');
        $own = $this->employee($actor);
        foreach ([null, '', 'unknown'] as $status) {
            DailyAttendance::query()->create(['employee_id' => $own->id, 'attendance_date' => '2026-10-'.(10 + DailyAttendance::query()->count()), 'status' => $status]);
        }
        $summary = app(DashboardService::class)->get($actor, ['start' => '2026-10-01', 'end' => '2026-10-31']);
        $this->assertSame(3, $summary['attendance']['total']);
        $this->assertSame(['unknown' => 3], $summary['attendance']['by_status']);
    }

    public function test_workspace_form_requests_validate_real_dates_ranges_types_and_scopes(): void
    {
        $actor = $this->user('holidays.view');
        $valid = ['start' => '2026-10-01', 'end' => '2026-10-31', 'scope' => 'visible'];
        $this->assertSame($valid, $this->validateRequest(GetDashboardRequest::class, $valid, $actor));
        $this->assertSame($valid + ['types' => []], $this->validateRequest(GetCalendarRequest::class, $valid + ['types' => ''], $actor));
        $this->assertInvalidRequest(GetCalendarRequest::class, ['start' => '2026-02-30', 'end' => '2026-03-01'], $actor, fields: ['start']);
        $this->assertInvalidRequest(GetCalendarRequest::class, ['start' => '2026-01-01', 'end' => '2026-03-04'], $actor, fields: ['end']);
        $this->assertInvalidRequest(GetDashboardRequest::class, $valid + ['employee_id' => 99], $actor, fields: ['employee_id']);
        $this->assertUnauthorizedRequest(GetCalendarRequest::class, $valid + ['types' => ['leave']], $actor);
        $this->assertUnauthorizedRequest(GetDashboardRequest::class, ['scope' => 'mine'], $actor);
    }

    public function test_workspace_gates_use_active_accounts_and_existing_module_permissions(): void
    {
        $plain = $this->user();
        $this->assertTrue($plain->can('viewDashboard'));
        $this->assertFalse($plain->can('viewCalendar'));
        foreach (WorkspacePolicy::CALENDAR_PERMISSIONS as $type => $permissions) {
            foreach ($permissions as $permission) {
                $actor = $this->user($permission);
                $this->assertTrue($actor->can('viewCalendar'));
                $this->assertTrue($actor->can('viewCalendarType', $type));
                $actor->update(['is_active' => false]);
                $this->assertFalse($actor->can('viewDashboard'));
                $this->assertFalse($actor->can('viewCalendarType', $type));
            }
        }
        $this->assertFalse($plain->can('viewCalendarType', 'salary'));
    }

    public function test_services_build_scoped_summaries_and_safe_calendar_events_from_existing_records(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 4)->setTime(10, 0));
        $actor = $this->user('attendance.view', 'cto.view', 'holidays.view');
        $own = $this->employee($actor);
        $other = $this->employee();
        foreach ([$own, $other] as $employee) {
            DailyAttendance::query()->create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-02', 'status' => 'present', 'worked_minutes' => 480, 'late_minutes' => 15]);
            CtoRequest::query()->create(['employee_id' => $employee->id, 'start_at' => '2026-10-05 13:00:00', 'end_at' => '2026-10-05 15:00:00', 'requested_minutes' => 120, 'approved_minutes' => 120, 'status' => 'approved', 'reason' => 'Private employee reason', 'review_remarks' => 'Private review', 'created_by_user_id' => $actor->id]);
        }
        Holiday::query()->create(['name' => 'Company day', 'holiday_date' => '2026-10-06', 'holiday_type' => 'company', 'is_active' => true]);
        $filters = ['start' => '2026-10-01', 'end' => '2026-10-31'];
        $summary = app(DashboardService::class)->get($actor, $filters);
        $this->assertSame('mine', $summary['scope']);
        $this->assertNull($summary['workforce']);
        $this->assertSame(1, $summary['attendance']['total']);
        $this->assertSame(15, $summary['attendance']['late_minutes']);
        $this->assertSame([['date' => '2026-10-02', 'total' => 1]], $summary['attendance']['by_day']);
        $this->assertSame(1, $summary['requests'][0]['total']);
        $this->assertSame(0, $summary['approvals']['total']);
        $calendar = app(CalendarService::class)->get($actor, $filters);
        $this->assertCount(2, $calendar['events']);
        $cto = collect($calendar['events'])->firstWhere('type', 'cto');
        $this->assertSame($own->id, $cto['employee']['id']);
        $this->assertSame('2026-10-05T13:00:00+08:00', $cto['start']);
        $this->assertSame(['approved_minutes' => 120], $cto['details']);
        $this->assertStringNotContainsString('Private', json_encode($calendar, JSON_THROW_ON_ERROR));
        $holiday = collect($calendar['events'])->firstWhere('type', 'holiday');
        $this->assertSame('2026-10-07', $holiday['end']);
        $this->assertTrue($holiday['all_day']);
        $this->assertFalse($calendar['meta']['truncated']);
    }
}
