<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\JobApplication;
use App\Models\RecruitmentInterview;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class RecruitmentInterviewPolicyTest extends FormRequestTestCase
{
    #[DataProvider('staffPermissions')]
    public function test_staff_permissions_and_panel_membership_control_each_interview_ability(array $permissions, bool $assigned, bool $view, bool $manage, bool $join): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $staff = $this->user(...$permissions);
        $interview = RecruitmentInterview::factory()->create(['scheduled_at' => now()]);
        if ($assigned) {
            $interview->interviewers()->attach($staff);
        }

        $gate = Gate::forUser($staff);
        $this->assertSame($view, $gate->allows('view', $interview));
        $this->assertSame($manage, $gate->allows('schedule', [RecruitmentInterview::class, $interview->application]));
        $this->assertSame($manage, $gate->allows('cancel', $interview));
        $this->assertSame($manage, $gate->allows('complete', $interview));
        $this->assertSame($join, $gate->allows('participate', $interview));
        $this->assertSame($join, $gate->allows('join', $interview));
    }

    public function test_owner_can_view_and_join_but_cannot_schedule_or_complete_and_other_applicant_is_404(): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $owner = Applicant::factory()->create();
        $other = Applicant::factory()->create();
        $application = JobApplication::factory()->approved()->for($owner, 'applicant')->create();
        $interview = RecruitmentInterview::factory()->for($application, 'application')->create(['scheduled_at' => now()]);

        $gate = Gate::forUser($owner);
        $this->assertTrue($gate->allows('view', $interview));
        $this->assertTrue($gate->allows('join', $interview));
        $this->assertFalse($gate->allows('schedule', [RecruitmentInterview::class, $application]));
        $this->assertFalse($gate->allows('cancel', $interview));
        $this->assertFalse($gate->allows('complete', $interview));
        $this->assertSame(404, Gate::forUser($other)->inspect('view', $interview)->status());
        $this->assertSame(404, Gate::forUser($other)->inspect('participate', $interview)->status());
    }

    public function test_join_ability_disappears_when_room_closes_but_participant_permission_still_reaches_conflict_check(): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $staff = $this->user('recruitment.manage');
        $interview = RecruitmentInterview::factory()->cancelled()->hasAttached($staff, [], 'interviewers')->create(['scheduled_at' => now()]);

        $this->assertTrue(Gate::forUser($staff)->allows('participate', $interview));
        $this->assertFalse(Gate::forUser($staff)->allows('join', $interview));
        $staff->update(['is_active' => false]);
        $this->assertFalse(Gate::forUser($staff)->allows('participate', $interview));
        $this->assertFalse(Gate::forUser($staff)->allows('complete', $interview));
    }

    public static function staffPermissions(): array
    {
        return [
            'no permission' => [[], true, false, false, false],
            'viewer assigned' => [['recruitment.view'], true, true, false, false],
            'manager unassigned' => [['recruitment.view', 'recruitment.manage'], false, true, true, false],
            'manager assigned' => [['recruitment.view', 'recruitment.manage'], true, true, true, true],
        ];
    }
}
