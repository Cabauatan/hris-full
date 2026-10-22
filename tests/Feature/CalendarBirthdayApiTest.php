<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Division;
use App\Models\Position;
use App\Policies\WorkspacePolicy;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\FormRequestTestCase;

class CalendarBirthdayApiTest extends FormRequestTestCase
{
    public function test_linked_employee_sees_own_birthday_and_age_reached_on_that_day(): void
    {
        $this->travelTo('2026-10-08 12:00:00');
        $actor = $this->user();
        $own = $this->employee($actor, ['first_name' => 'Marcel', 'last_name' => 'Employee', 'birth_date' => '1995-10-31']);
        $this->employee(null, ['birth_date' => '1990-10-31']);

        $response = $this->actingAs($actor)->getJson('/api/v1/calendar')->assertOk()
            ->assertJsonPath('data.scope', 'mine')->assertJsonPath('data.allowed_types', ['birthday'])
            ->assertJsonCount(1, 'data.events')->assertJsonPath('data.events.0.id', 'birthday:'.$own->id.':2026')
            ->assertJsonPath('data.events.0.title', 'Birthday · Marcel Employee')
            ->assertJsonPath('data.events.0.start', '2026-10-31')->assertJsonPath('data.events.0.end', '2026-11-01')
            ->assertJsonPath('data.events.0.all_day', true)->assertJsonPath('data.events.0.details.age', 31)
            ->assertJsonPath('data.events.0.employee.id', $own->id)
            ->assertJsonPath('data.events.0.source', ['module' => 'employees', 'id' => $own->id]);

        $this->assertArrayNotHasKey('birth_date', $response->json('data.events.0.employee'));
        $this->assertSame(['age' => 31], $response->json('data.events.0.details'));
    }

    public function test_supervisor_visible_birthdays_include_only_self_and_direct_team(): void
    {
        $actor = $this->user('employees.view-team');
        $own = $this->employee($actor, ['birth_date' => '1990-10-01']);
        $report = $this->employee(null, ['birth_date' => '1995-10-31', 'supervisor_employee_id' => $own->id]);
        $this->employee(null, ['birth_date' => '2000-10-15', 'supervisor_employee_id' => $report->id]);
        $this->employee(null, ['birth_date' => '1992-10-15']);

        $this->actingAs($actor)->getJson($this->url(['scope' => 'visible']))->assertOk()
            ->assertJsonCount(2, 'data.events')->assertJsonPath('data.events.0.employee.id', $own->id)
            ->assertJsonPath('data.events.1.employee.id', $report->id);
    }

    #[TestWith(['mine', 1])]
    #[TestWith(['visible', 2])]
    public function test_hr_calendar_birthdays_respect_selected_scope(string $scope, int $count): void
    {
        $actor = $this->user('employees.view');
        $own = $this->employee($actor, ['birth_date' => '1990-10-01']);
        $this->employee(null, ['birth_date' => '1995-10-31']);

        $this->actingAs($actor)->getJson($this->url(compact('scope')))->assertOk()
            ->assertJsonCount($count, 'data.events')->assertJsonPath('data.events.0.employee.id', $own->id);
    }

    public function test_linked_employee_visible_scope_does_not_expose_unrelated_birthdays(): void
    {
        $actor = $this->user('leave.view');
        $own = $this->employee($actor, ['birth_date' => '1995-10-01']);
        $this->employee(null, ['birth_date' => '1990-10-01']);

        $this->actingAs($actor)->getJson($this->url(['scope' => 'visible']))->assertOk()
            ->assertJsonCount(1, 'data.events')->assertJsonPath('data.events.0.employee.id', $own->id);
    }

    public function test_visible_birthdays_include_coworkers_with_another_position_in_the_same_department(): void
    {
        $actor = $this->user();
        $department = $this->department();
        $own = $this->employee($actor, ['position_id' => $this->position($department)->id, 'birth_date' => '1990-10-01']);
        $coworker = $this->employee(null, ['position_id' => $this->position($department)->id, 'birth_date' => '1995-10-02']);
        $this->employee(null, ['position_id' => $this->position($this->department())->id, 'birth_date' => '1995-10-03']);

        $this->actingAs($actor)->getJson($this->url(['scope' => 'visible']))->assertOk()
            ->assertJsonCount(2, 'data.events')->assertJsonPath('data.events.0.employee.id', $own->id)
            ->assertJsonPath('data.events.1.employee.id', $coworker->id);

        $this->getJson('/api/v1/employees/'.$coworker->id)->assertForbidden();
    }

    public function test_visible_birthdays_include_coworkers_in_another_department_of_the_same_active_division(): void
    {
        $actor = $this->user();
        $division = Division::query()->create(['code' => 'OPERATIONS', 'name' => 'Operations', 'is_active' => true]);
        $own = $this->employee($actor, ['position_id' => $this->position($this->department($division))->id, 'birth_date' => '1990-10-01']);
        $coworker = $this->employee(null, ['position_id' => $this->position($this->department($division))->id, 'birth_date' => '1995-10-02']);
        $this->employee(null, ['position_id' => $this->position($this->department())->id, 'birth_date' => '1995-10-03']);

        $this->actingAs($actor)->getJson($this->url(['scope' => 'visible']))->assertOk()
            ->assertJsonCount(2, 'data.events')->assertJsonPath('data.events.0.employee.id', $own->id)
            ->assertJsonPath('data.events.1.employee.id', $coworker->id);
    }

    public function test_my_calendar_keeps_only_my_birthday_even_with_department_coworkers(): void
    {
        $actor = $this->user();
        $position = $this->position($this->department());
        $own = $this->employee($actor, ['position_id' => $position->id, 'birth_date' => '1990-10-01']);
        $this->employee(null, ['position_id' => $position->id, 'birth_date' => '1995-10-02']);

        $this->actingAs($actor)->getJson($this->url(['scope' => 'mine']))->assertOk()
            ->assertJsonCount(1, 'data.events')->assertJsonPath('data.events.0.employee.id', $own->id);
    }

    public function test_visible_birthdays_omit_inactive_and_missing_coworker_hierarchies(): void
    {
        $actor = $this->user();
        $division = Division::query()->create(['code' => 'OPERATIONS', 'name' => 'Operations', 'is_active' => true]);
        $department = $this->department($division);
        $position = $this->position($department);
        $own = $this->employee($actor, ['position_id' => $position->id, 'birth_date' => '1990-10-01']);
        $this->employee(null, ['position_id' => $position->id, 'birth_date' => '1990-10-02', 'is_active' => false]);
        $this->employee(null, ['position_id' => $this->position($department, ['is_active' => false])->id, 'birth_date' => '1990-10-03']);
        $this->employee(null, ['position_id' => $this->position($this->department($division, ['is_active' => false]))->id, 'birth_date' => '1990-10-04']);
        $this->employee(null, ['position_id' => $this->position()->id, 'birth_date' => '1990-10-05']);
        $this->employee(null, ['birth_date' => '1990-10-06']);

        $this->actingAs($actor)->getJson($this->url(['scope' => 'visible']))->assertOk()
            ->assertJsonCount(1, 'data.events')->assertJsonPath('data.events.0.employee.id', $own->id);
    }

    public function test_inactive_division_does_not_share_birthdays_across_departments(): void
    {
        $actor = $this->user();
        $division = Division::query()->create(['code' => 'OPERATIONS', 'name' => 'Operations', 'is_active' => false]);
        $department = $this->department($division);
        $own = $this->employee($actor, ['position_id' => $this->position($department)->id, 'birth_date' => '1990-10-01']);
        $sameDepartment = $this->employee(null, ['position_id' => $this->position($department)->id, 'birth_date' => '1990-10-02']);
        $this->employee(null, ['position_id' => $this->position($this->department($division))->id, 'birth_date' => '1990-10-03']);

        $this->actingAs($actor)->getJson($this->url(['scope' => 'visible']))->assertOk()
            ->assertJsonCount(2, 'data.events')->assertJsonPath('data.events.0.employee.id', $own->id)
            ->assertJsonPath('data.events.1.employee.id', $sameDepartment->id);
    }

    #[TestWith(['position', 1])]
    #[TestWith(['department', 1])]
    #[TestWith(['employee', 0])]
    #[TestWith(['missing_department', 1])]
    public function test_ineligible_viewer_hierarchy_does_not_expand_birthday_visibility(string $inactive, int $count): void
    {
        $actor = $this->user();
        $division = Division::query()->create(['code' => 'OPERATIONS', 'name' => 'Operations', 'is_active' => true]);
        $department = $this->department($division);
        $position = $this->position($department);
        $own = $this->employee($actor, ['position_id' => $position->id, 'birth_date' => '1990-10-01']);
        $this->employee(null, ['position_id' => $this->position($this->department($division))->id, 'birth_date' => '1990-10-02']);
        match ($inactive) {
            'position' => $position->update(['is_active' => false]),
            'department' => $department->update(['is_active' => false]),
            'employee' => $own->update(['is_active' => false]),
            'missing_department' => $position->update(['department_id' => null]),
        };

        $this->actingAs($actor)->getJson($this->url(['scope' => 'visible']))->assertOk()
            ->assertJsonCount($count, 'data.events');
    }

    public function test_unlinked_account_without_employee_read_permission_cannot_select_birthdays(): void
    {
        $actor = $this->user('holidays.view');
        $this->employee(null, ['birth_date' => '1990-10-01']);

        $this->actingAs($actor)->getJson($this->url())->assertForbidden();
    }

    public function test_inactive_linked_account_cannot_read_birthday_calendar(): void
    {
        $actor = $this->user();
        $this->employee($actor, ['birth_date' => '1990-10-01']);
        $actor->update(['is_active' => false]);

        $this->actingAs($actor)->getJson($this->url())->assertForbidden();
    }

    #[TestWith(['2000-02-29', '2026-02-28', '2026-02-28', '2026-02-28', '2026-03-01', 26])]
    #[TestWith(['2000-02-29', '2028-02-28', '2028-02-29', '2028-02-29', '2028-03-01', 28])]
    #[TestWith(['1995-10-31', '2025-10-31', '2025-10-31', '2025-10-31', '2025-11-01', 30])]
    #[TestWith(['1995-10-31', '2027-10-31', '2027-10-31', '2027-10-31', '2027-11-01', 32])]
    public function test_annual_birthday_dates_and_ages_use_the_viewed_year(
        string $birthDate, string $start, string $end, string $eventStart, string $eventEnd, int $age,
    ): void {
        $actor = $this->user();
        $this->employee($actor, ['birth_date' => $birthDate]);

        $this->actingAs($actor)->getJson($this->url(compact('start', 'end')))->assertOk()
            ->assertJsonCount(1, 'data.events')->assertJsonPath('data.events.0.start', $eventStart)
            ->assertJsonPath('data.events.0.end', $eventEnd)->assertJsonPath('data.events.0.details.age', $age);
    }

    public function test_leap_birthday_does_not_shift_to_february_28_in_a_leap_year(): void
    {
        $actor = $this->user();
        $this->employee($actor, ['birth_date' => '2000-02-29']);

        $this->actingAs($actor)->getJson($this->url(['start' => '2028-02-28', 'end' => '2028-02-28']))
            ->assertOk()->assertJsonPath('data.events', []);
    }

    public function test_birthdays_crossing_year_boundary_are_sorted_and_use_each_occurrence_year(): void
    {
        $actor = $this->user('employees.view');
        $this->employee(null, ['birth_date' => '1990-01-01']);
        $this->employee(null, ['birth_date' => '1995-12-31']);

        $this->actingAs($actor)->getJson($this->url(['start' => '2026-12-31', 'end' => '2027-01-01']))->assertOk()
            ->assertJsonCount(2, 'data.events')->assertJsonPath('data.events.0.start', '2026-12-31')
            ->assertJsonPath('data.events.0.details.age', 31)->assertJsonPath('data.events.1.start', '2027-01-01')
            ->assertJsonPath('data.events.1.details.age', 37);
    }

    public function test_calendar_omits_missing_inactive_and_out_of_range_birthdays(): void
    {
        $actor = $this->user('employees.view');
        $this->employee();
        $this->employee(null, ['birth_date' => '1995-10-15', 'is_active' => false]);
        $this->employee(null, ['birth_date' => '1995-09-30']);
        $this->employee(null, ['birth_date' => '1995-11-01']);
        $this->employee(null, ['birth_date' => '2027-10-15']);

        $this->actingAs($actor)->getJson($this->url())->assertOk()->assertJsonPath('data.events', []);
    }

    public function test_deselecting_birthdays_omits_them(): void
    {
        $actor = $this->user();
        $this->employee($actor, ['birth_date' => '1995-10-31']);

        $this->actingAs($actor)->getJson($this->url(['types' => '']))->assertOk()
            ->assertJsonPath('data.allowed_types', ['birthday'])->assertJsonPath('data.events', []);
    }

    public function test_all_eight_authorized_calendar_types_can_be_selected_together(): void
    {
        $actor = $this->user(...array_unique(array_merge(...array_values(WorkspacePolicy::CALENDAR_PERMISSIONS))));

        $this->actingAs($actor)->getJson($this->url(['types' => array_keys(WorkspacePolicy::CALENDAR_PERMISSIONS)]))
            ->assertOk()->assertJsonCount(8, 'data.allowed_types');
    }

    public function test_calendar_event_limit_warns_when_birthdays_exceed_500(): void
    {
        $actor = $this->user('employees.view');
        for ($index = 0; $index < 501; $index++) {
            $this->employee(null, ['birth_date' => '1995-10-31']);
        }
        $early = $this->employee(null, ['birth_date' => '1995-10-01']);

        $this->actingAs($actor)->getJson($this->url())->assertOk()
            ->assertJsonCount(500, 'data.events')->assertJsonPath('data.meta.truncated', true)
            ->assertJsonPath('data.events.0.employee.id', $early->id)
            ->assertJsonCount(1, 'data.meta.warnings');
    }

    private function url(array $query = []): string
    {
        return '/api/v1/calendar?'.http_build_query(array_replace([
            'start' => '2026-10-01', 'end' => '2026-10-31', 'types' => ['birthday'],
        ], $query));
    }

    private function department(?Division $division = null, array $attributes = []): Department
    {
        return Department::query()->create(array_replace([
            'division_id' => $division?->id, 'code' => (string) Str::uuid(), 'name' => 'Test department', 'is_active' => true,
        ], $attributes));
    }

    private function position(?Department $department = null, array $attributes = []): Position
    {
        return Position::query()->create(array_replace([
            'department_id' => $department?->id, 'code' => (string) Str::uuid(), 'name' => 'Test position', 'is_active' => true,
        ], $attributes));
    }
}
