<?php

namespace Tests\Feature;

use App\Models\JobApplication;
use App\Models\RecruitmentInterview;
use App\Models\Role;
use Carbon\CarbonInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class RecruitmentInterviewFoundationTest extends FormRequestTestCase
{
    public function test_interview_round_schedule_and_private_notes_are_saved_without_creating_employee_identity(): void
    {
        $this->freezeTime();

        $interview = RecruitmentInterview::factory()->finalRound()->create([
            'scheduled_at' => '2026-10-20 14:30:00',
            'private_notes' => 'Staff-only deliberation.',
        ])->refresh();

        $this->assertSame('final', $interview->round);
        $this->assertSame('scheduled', $interview->status);
        $this->assertSame(30, $interview->duration_minutes);
        $this->assertInstanceOf(CarbonInterface::class, $interview->scheduled_at);
        $this->assertSame('2026-10-20 14:30:00', $interview->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertNull($interview->started_at);
        $this->assertNull($interview->ended_at);
        $this->assertSame('Staff-only deliberation.', $interview->private_notes);
        $this->assertArrayNotHasKey('private_notes', $interview->toArray());
        $this->assertDatabaseCount('applicants', 1);
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('users', 0);
    }

    #[DataProvider('interviewerCounts')]
    public function test_single_or_panel_staff_relations_attach_unique_participants_with_timestamps(int $count): void
    {
        $this->freezeTime();
        $this->seed(RolePermissionSeeder::class);
        $role = Role::query()->where('code', 'hr_staff')->firstOrFail();
        $staff = collect(range(1, $count))->map(function () use ($role) {
            $user = $this->user();
            $user->roles()->attach($role);

            return $user;
        });

        $interview = RecruitmentInterview::factory()->hasAttached($staff, [], 'interviewers')->create();

        $this->assertSame($staff->pluck('id')->all(), $interview->interviewers()->orderBy('users.id')->pluck('users.id')->all());
        $this->assertDatabaseCount('recruitment_interview_user', $count);
        foreach ($interview->interviewers as $interviewer) {
            $this->assertTrue($interviewer->hasPermission('recruitment.manage'));
            $this->assertInstanceOf(CarbonInterface::class, $interviewer->pivot->created_at);
            $this->assertSame(now()->format('Y-m-d H:i:s'), $interviewer->pivot->created_at->format('Y-m-d H:i:s'));
        }
    }

    public function test_duplicate_interviewer_in_the_same_panel_is_rejected_by_the_schema(): void
    {
        $staff = $this->user();
        $interview = RecruitmentInterview::factory()->hasAttached($staff, [], 'interviewers')->create();

        $this->expectException(QueryException::class);

        $interview->interviewers()->attach($staff);
    }

    public function test_interview_uuid_cannot_be_reused_for_another_application(): void
    {
        $interview = RecruitmentInterview::factory()->create();

        $this->expectException(QueryException::class);

        RecruitmentInterview::factory()->create(['uuid' => $interview->uuid]);
    }

    public function test_cancelled_round_history_allows_a_separate_replacement_with_a_new_room_uuid(): void
    {
        $application = JobApplication::factory()->approved()->create();
        $original = RecruitmentInterview::factory()->for($application, 'application')->cancelled()->create();

        $replacement = RecruitmentInterview::factory()->for($application, 'application')->create();

        $this->assertSame('initial', $replacement->round);
        $this->assertNotSame($original->uuid, $replacement->uuid);
        $this->assertDatabaseCount('recruitment_interviews', 2);
        $this->assertSame('cancelled', $original->refresh()->status);
        $this->assertSame('scheduled', $replacement->refresh()->status);
    }

    public function test_application_relations_keep_private_interview_notes_hidden_when_nested(): void
    {
        $staff = $this->user();
        $application = JobApplication::factory()->approved()->create();
        $interview = RecruitmentInterview::factory()->for($application, 'application')->create([
            'scheduled_by' => $staff->id,
            'completed_by' => $staff->id,
            'private_notes' => 'Sensitive panel discussion.',
        ]);

        $application->load('interviews');

        $this->assertTrue($interview->application->is($application));
        $this->assertTrue($interview->scheduler->is($staff));
        $this->assertTrue($interview->completer->is($staff));
        $this->assertTrue($application->interviews()->sole()->is($interview));
        $this->assertArrayNotHasKey('private_notes', $application->toArray()['interviews'][0]);
        $this->assertStringNotContainsString('Sensitive panel discussion.', $application->toJson());
    }

    public function test_application_with_interview_history_cannot_be_deleted(): void
    {
        $interview = RecruitmentInterview::factory()->create();
        $application = $interview->application;

        try {
            $application->delete();
        } catch (QueryException) {
            $this->assertModelExists($application);
            $this->assertModelExists($interview);

            return;
        }

        $this->fail('Expected interview history to prevent application deletion.');
    }

    public function test_deleting_staff_preserves_interview_history_and_removes_only_their_panel_membership(): void
    {
        $departing = $this->user();
        $remaining = $this->user();
        $interview = RecruitmentInterview::factory()->hasAttached([$departing, $remaining], [], 'interviewers')->create([
            'scheduled_by' => $departing->id,
            'completed_by' => $departing->id,
            'started_at' => '2026-10-20 14:30:00',
            'ended_at' => '2026-10-20 15:00:00',
        ]);

        $departing->delete();

        $this->assertModelExists($interview);
        $this->assertNull($interview->refresh()->scheduled_by);
        $this->assertNull($interview->completed_by);
        $this->assertSame([$remaining->id], $interview->interviewers()->pluck('users.id')->all());
        $this->assertSame('2026-10-20 14:30:00', $interview->started_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-20 15:00:00', $interview->ended_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('recruitment_interview_user', 1);
    }

    public function test_deleting_an_unused_interview_removes_its_pivot_memberships_without_deleting_staff(): void
    {
        $staff = $this->user();
        $interview = RecruitmentInterview::factory()->hasAttached($staff, [], 'interviewers')->create();

        $interview->delete();

        $this->assertModelExists($staff);
        $this->assertDatabaseCount('recruitment_interview_user', 0);
        $this->assertDatabaseCount('recruitment_interviews', 0);
    }

    public static function interviewerCounts(): array
    {
        return ['one HR interviewer' => [1], 'four HR interviewers' => [4]];
    }
}
