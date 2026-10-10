<?php

namespace Tests\Feature;

use App\Events\InterviewEnded;
use App\Events\InterviewSignal;
use App\Models\Applicant;
use App\Models\JobApplication;
use App\Models\RecruitmentInterview;
use App\Services\Recruitment\RecruitmentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\FormRequestTestCase;

class RecruitmentInterviewApiTest extends FormRequestTestCase
{
    #[DataProvider('protectedEndpoints')]
    public function test_guest_cannot_access_interview_api_without_the_correct_session(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized();
    }

    public function test_staff_schedules_an_interview_with_safe_panel_details_and_applicant_never_sees_private_notes(): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $application = JobApplication::factory()->approved()->create();
        $response = $this->actingAs($staff, 'web')->postJson('/api/v1/recruitment/applications/'.$application->id.'/interviews', [
            'round' => 'initial', 'scheduled_at' => '2026-10-08T01:00:00Z', 'duration_minutes' => 30,
            'interviewer_ids' => [$staff->id], 'private_notes' => 'Internal deliberation.',
            'status' => 'completed', 'scheduled_by' => 999, 'uuid' => 'spoofed',
        ])->assertCreated()->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.scheduled_at', '2026-10-08T09:00:00+08:00')
            ->assertJsonPath('data.private_notes', 'Internal deliberation.')
            ->assertJsonPath('data.interviewers.0.id', $staff->id)
            ->assertJsonPath('data.scheduled_by.id', $staff->id);
        $id = $response->json('data.id');
        $this->assertDatabaseHas('recruitment_interviews', ['id' => $id, 'job_application_id' => $application->id, 'status' => 'scheduled', 'private_notes' => 'Internal deliberation.']);
        $this->assertDatabaseHas('recruitment_interview_user', ['recruitment_interview_id' => $id, 'user_id' => $staff->id]);

        $this->actingAs($application->applicant, 'applicant')->getJson('/api/v1/applicant/interviews/'.$id)
            ->assertOk()->assertJsonMissingPath('data.private_notes')->assertJsonMissingPath('data.interviewers.0.email')
            ->assertJsonMissingPath('data.scheduled_by.email')->assertJsonPath('data.abilities.complete', false)
            ->assertJsonPath('data.join_available_from', '2026-10-08T08:45:00+08:00')
            ->assertJsonPath('data.join_available_until', '2026-10-08T10:00:00+08:00');
        $this->getJson('/api/v1/applicant/applications/'.$application->id.'/interviews')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.private_notes');
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_candidate_ownership_is_hidden_and_each_endpoint_uses_its_declared_guard(): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $interview = RecruitmentInterview::factory()->create(['scheduled_at' => now()]);
        $owner = $interview->application->applicant;
        $other = Applicant::factory()->create();
        $this->assertSame($staff->id, $owner->id);

        $this->actingAs($staff, 'web')->getJson('/api/v1/applicant/interviews/'.$interview->id)->assertUnauthorized();
        Auth::guard('web')->logout();
        $this->actingAs($owner, 'applicant')->getJson('/api/v1/recruitment/interviews/'.$interview->id)->assertUnauthorized();
        $this->actingAs($other, 'applicant')->getJson('/api/v1/applicant/interviews/'.$interview->id)->assertNotFound();
        $this->postJson('/api/v1/applicant/interviews/'.$interview->id.'/join')->assertNotFound();
        $this->getJson('/api/v1/applicant/applications/'.$interview->job_application_id.'/interviews')->assertNotFound();
    }

    public function test_unassigned_managers_can_view_but_cannot_join_and_assigned_panel_has_collision_safe_roster(): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $unassigned = $this->user('recruitment.view', 'recruitment.manage');
        $interview = RecruitmentInterview::factory()->hasAttached($staff, [], 'interviewers')->create(['scheduled_at' => now()]);
        $owner = $interview->application->applicant;
        $this->actingAs($unassigned, 'web')->getJson('/api/v1/recruitment/interviews/'.$interview->id)->assertOk()->assertJsonPath('data.abilities.join', false);
        $this->postJson('/api/v1/recruitment/interviews/'.$interview->id.'/join')->assertForbidden();
        $this->assertNull($interview->refresh()->started_at);

        $this->actingAs($staff, 'web')->postJson('/api/v1/recruitment/interviews/'.$interview->id.'/join')
            ->assertOk()->assertJsonPath('data.actor.key', 'user:'.$staff->id)
            ->assertJsonPath('data.participants.0.key', 'applicant:'.$owner->id)
            ->assertJsonPath('data.participants.1.key', 'user:'.$staff->id)
            ->assertJsonPath('data.ice_servers', []);
        $this->actingAs($owner, 'applicant')->postJson('/api/v1/applicant/interviews/'.$interview->id.'/join')
            ->assertOk()->assertJsonPath('data.actor.key', 'applicant:'.$owner->id);
        $this->assertDatabaseHas('recruitment_interviews', ['id' => $interview->id, 'started_at' => '2026-10-07 09:00:00']);
    }

    public function test_join_rejects_closed_or_outside_window_rooms_with_409_and_disabled_accounts_with_403(): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $interview = RecruitmentInterview::factory()->hasAttached($staff, [], 'interviewers')->create(['scheduled_at' => '2026-10-08 09:00:00']);
        $this->actingAs($staff, 'web')->postJson('/api/v1/recruitment/interviews/'.$interview->id.'/join')->assertConflict();
        $interview->update(['scheduled_at' => now(), 'status' => 'completed']);
        $this->postJson('/api/v1/recruitment/interviews/'.$interview->id.'/join')->assertConflict();
        $interview->update(['status' => 'scheduled']);
        $staff->update(['is_active' => false]);
        $this->postJson('/api/v1/recruitment/interviews/'.$interview->id.'/join')->assertForbidden();
        $this->assertNull($interview->refresh()->started_at);
    }

    public function test_only_active_recruitment_managers_are_listed_as_interviewer_choices(): void
    {
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $this->user('recruitment.view');
        $inactive = $this->user('recruitment.manage');
        $inactive->update(['is_active' => false]);

        $this->actingAs($staff, 'web')->getJson('/api/v1/recruitment/interviewers')
            ->assertOk()->assertExactJson(['data' => [['id' => $staff->id, 'name' => $staff->name]]]);
        $this->actingAs($this->user(), 'web')->getJson('/api/v1/recruitment/interviewers')->assertForbidden();
    }

    public function test_signal_preserves_sdp_line_endings_ignores_sender_spoof_and_keeps_database_unchanged(): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $interview = RecruitmentInterview::factory()->hasAttached($staff, [], 'interviewers')->create(['scheduled_at' => now()]);
        $owner = $interview->application->applicant;
        $before = $interview->refresh()->getAttributes();
        $sdp = "v=0\r\ns=Fixture\r\n";
        Event::fake([InterviewSignal::class]);

        $this->actingAs($staff, 'web')->postJson('/api/v1/recruitment/interviews/'.$interview->id.'/signal', [
            'type' => 'offer', 'target_key' => 'applicant:'.$owner->id, 'payload' => ['sdp' => $sdp],
            'sender' => ['kind' => 'applicant', 'id' => $owner->id], 'channel_name' => 'spoofed',
        ])->assertOk();

        Event::assertDispatched(InterviewSignal::class, function (InterviewSignal $event) use ($staff, $owner, $interview, $sdp): bool {
            $this->assertSame('user:'.$staff->id, $event->sender['key']);
            $this->assertSame('applicant:'.$owner->id, $event->targetKey);
            $this->assertSame(['sdp' => $sdp], $event->payload);
            $this->assertSame('private-Recruitment.Interview.'.$interview->uuid, (string) $event->broadcastOn()[0]);

            return true;
        });
        $this->assertSame($before, $interview->refresh()->getAttributes());
    }

    public function test_ready_can_broadcast_or_reply_to_one_panel_member_and_invalid_targets_never_broadcast(): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $staff = $this->user('recruitment.manage');
        $interview = RecruitmentInterview::factory()->hasAttached($staff, [], 'interviewers')->create(['scheduled_at' => now()]);
        $owner = $interview->application->applicant;
        Event::fake([InterviewSignal::class]);
        $this->actingAs($owner, 'applicant')->postJson('/api/v1/applicant/interviews/'.$interview->id.'/signal', ['type' => 'ready', 'payload' => (object) []])->assertOk();
        $this->postJson('/api/v1/applicant/interviews/'.$interview->id.'/signal', ['type' => 'ready', 'target_key' => 'user:'.$staff->id, 'payload' => (object) []])->assertOk();
        $this->postJson('/api/v1/applicant/interviews/'.$interview->id.'/signal', ['type' => 'ready', 'target_key' => 'user:999999'])->assertUnprocessable()->assertJsonValidationErrors('target_key');
        $this->postJson('/api/v1/applicant/interviews/'.$interview->id.'/signal', ['type' => 'ready', 'target_key' => 'applicant:'.$owner->id])->assertUnprocessable()->assertJsonValidationErrors('target_key');

        Event::assertDispatchedTimes(InterviewSignal::class, 2);
    }

    public function test_byte_and_wire_limits_reject_signals_before_broadcasting(): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $staff = $this->user('recruitment.manage');
        $interview = RecruitmentInterview::factory()->hasAttached($staff, [], 'interviewers')->create(['scheduled_at' => now()]);
        Event::fake([InterviewSignal::class]);

        $this->actingAs($staff, 'web')->postJson('/api/v1/recruitment/interviews/'.$interview->id.'/signal', [
            'type' => 'offer', 'target_key' => 'applicant:'.$interview->application->applicant_id,
            'payload' => ['sdp' => str_repeat('é', 4097)],
        ])->assertUnprocessable()->assertJsonValidationErrors('payload.sdp');
        $this->postJson('/api/v1/recruitment/interviews/'.$interview->id.'/signal', [
            'type' => 'offer', 'target_key' => 'applicant:'.$interview->application->applicant_id,
            'payload' => ['sdp' => 'v=0'.str_repeat("\r\n", 2500)],
        ])->assertUnprocessable()->assertJsonValidationErrors('payload');
        Event::assertNotDispatched(InterviewSignal::class);
    }

    public function test_private_broadcast_authorization_uses_endpoint_guard_even_when_both_identities_share_an_id(): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $interview = RecruitmentInterview::factory()->create(['scheduled_at' => now()]);
        $owner = $interview->application->applicant;
        $this->assertSame($staff->id, $owner->id);
        $this->fixtureBroadcaster();
        $input = ['socket_id' => '123.456', 'channel_name' => 'private-Recruitment.Interview.'.$interview->uuid];

        $this->actingAs($staff, 'web')->actingAs($owner, 'applicant');
        $this->postJson('/api/v1/recruitment/interviews/broadcasting/auth', $input)->assertForbidden();
        $response = $this->postJson('/api/v1/applicant/interviews/broadcasting/auth', $input)->assertOk()->assertJsonStructure(['auth']);
        $this->assertStringStartsWith('interview-public:', $response->json('auth'));
        $this->assertStringNotContainsString('interview-secret', $response->getContent());
        $interview->interviewers()->attach($staff);
        $this->postJson('/api/v1/recruitment/interviews/broadcasting/auth', $input)->assertOk();
        $interview->update(['status' => 'cancelled']);
        $this->postJson('/api/v1/applicant/interviews/broadcasting/auth', $input)->assertForbidden();
    }

    public function test_completion_and_rejection_stop_rooms_and_broadcast_only_private_lifecycle_status(): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $interview = RecruitmentInterview::factory()->hasAttached($staff, [], 'interviewers')->create(['scheduled_at' => now()]);
        Event::fake([InterviewEnded::class]);
        $this->actingAs($staff, 'web')->postJson('/api/v1/recruitment/interviews/'.$interview->id.'/complete', ['private_notes' => 'HR outcome.'])
            ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.private_notes', 'HR outcome.');
        $this->postJson('/api/v1/recruitment/interviews/'.$interview->id.'/complete')->assertConflict();
        $other = RecruitmentInterview::factory()->hasAttached($staff, [], 'interviewers')->create(['scheduled_at' => now()]);
        $this->postJson('/api/v1/recruitment/applications/'.$other->job_application_id.'/reject')->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertDatabaseHas('recruitment_interviews', ['id' => $other->id, 'status' => 'cancelled', 'ended_at' => '2026-10-07 09:00:00']);
        $this->postJson('/api/v1/recruitment/interviews/'.$other->id.'/join')->assertConflict();
        Event::assertDispatchedTimes(InterviewEnded::class, 2);
        Event::assertDispatched(InterviewEnded::class, fn (InterviewEnded $event): bool => $event->broadcastWith() === ['interview_uuid' => $other->uuid, 'status' => 'cancelled']);
    }

    public function test_rejected_parent_and_room_cancellation_roll_back_together_without_broadcast(): void
    {
        $staff = $this->user('recruitment.manage');
        $interview = RecruitmentInterview::factory()->create();
        $application = $interview->application;
        Event::fake([InterviewEnded::class]);

        try {
            DB::transaction(function () use ($application, $staff): void {
                app(RecruitmentService::class)->reject($application, $staff);
                throw new RuntimeException('Fixture rollback.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Fixture rollback.', $exception->getMessage());
        }
        $this->assertSame('approved', $application->refresh()->status);
        $this->assertSame('scheduled', $interview->refresh()->status);
        Event::assertNotDispatched(InterviewEnded::class);
    }

    public function test_join_limits_do_not_merge_user_and_applicant_with_the_same_numeric_id(): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $staff = $this->user('recruitment.manage');
        $interview = RecruitmentInterview::factory()->hasAttached($staff, [], 'interviewers')->create(['scheduled_at' => now()]);
        $owner = $interview->application->applicant;

        $this->actingAs($staff, 'web');
        for ($index = 0; $index < 20; $index++) {
            $this->postJson('/api/v1/recruitment/interviews/'.$interview->id.'/join')->assertOk();
        }
        $this->postJson('/api/v1/recruitment/interviews/'.$interview->id.'/join')->assertTooManyRequests();
        $this->actingAs($owner, 'applicant')->postJson('/api/v1/applicant/interviews/'.$interview->id.'/join')->assertOk();
    }

    public function test_interview_state_changes_require_real_csrf_tokens(): void
    {
        $staff = $this->user('recruitment.manage');
        $interview = RecruitmentInterview::factory()->create();
        Event::fake([InterviewEnded::class]);
        $this->actingAs($staff, 'web');
        $this->app->instance('env', 'local');
        try {
            $this->postJson('/api/v1/recruitment/interviews/'.$interview->id.'/cancel')->assertStatus(419);
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->assertSame('scheduled', $interview->refresh()->status);
        Event::assertNotDispatched(InterviewEnded::class);
    }

    public function test_four_staff_panel_has_five_collision_safe_room_participants(): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $panel = collect(range(1, 4))->map(fn () => $this->user('recruitment.manage'));
        $interview = RecruitmentInterview::factory()->hasAttached($panel, [], 'interviewers')->create(['scheduled_at' => now()]);

        $response = $this->actingAs($interview->application->applicant, 'applicant')
            ->postJson('/api/v1/applicant/interviews/'.$interview->id.'/join')->assertOk()->assertJsonCount(5, 'data.participants');

        $this->assertSame(['applicant:1', 'user:1', 'user:2', 'user:3', 'user:4'], array_column($response->json('data.participants'), 'key'));
        $response->assertJsonMissingPath('data.participants.1.email')->assertJsonMissingPath('data.participants.1.permissions');
    }

    public function test_schedule_endpoint_applies_form_validation_without_creating_partial_records(): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $staff = $this->user('recruitment.manage');
        $application = JobApplication::factory()->approved()->create();

        $this->actingAs($staff, 'web')->postJson('/api/v1/recruitment/applications/'.$application->id.'/interviews', [
            'round' => 'initial', 'scheduled_at' => '2026-10-08T09:00:00+08:00', 'duration_minutes' => 14, 'interviewer_ids' => [$staff->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('duration_minutes');

        $this->assertDatabaseCount('recruitment_interviews', 0);
        $this->assertDatabaseCount('recruitment_interview_user', 0);
    }

    #[DataProvider('closedApplications')]
    public function test_closed_parent_application_prevents_joining_and_signaling_with_409(string $status): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $staff = $this->user('recruitment.manage');
        $interview = RecruitmentInterview::factory()->hasAttached($staff, [], 'interviewers')->create(['scheduled_at' => now()]);
        $interview->application->update(['status' => $status]);
        Event::fake([InterviewSignal::class]);

        $this->actingAs($staff, 'web')->postJson('/api/v1/recruitment/interviews/'.$interview->id.'/join')->assertConflict();
        $this->postJson('/api/v1/recruitment/interviews/'.$interview->id.'/signal', ['type' => 'ready'])->assertConflict();

        $this->assertNull($interview->refresh()->started_at);
        Event::assertNotDispatched(InterviewSignal::class);
    }

    public function test_disabled_applicant_or_removed_panel_membership_cannot_use_a_room(): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $staff = $this->user('recruitment.manage');
        $interview = RecruitmentInterview::factory()->hasAttached($staff, [], 'interviewers')->create(['scheduled_at' => now()]);
        $owner = $interview->application->applicant;
        $owner->update(['is_active' => false]);
        Event::fake([InterviewSignal::class]);
        $this->actingAs($owner, 'applicant')->postJson('/api/v1/applicant/interviews/'.$interview->id.'/join')->assertForbidden();
        $interview->interviewers()->detach($staff);
        $this->actingAs($staff, 'web')->postJson('/api/v1/recruitment/interviews/'.$interview->id.'/signal', ['type' => 'ready'])->assertForbidden();

        $this->assertNull($interview->refresh()->started_at);
        Event::assertNotDispatched(InterviewSignal::class);
    }

    public static function closedApplications(): array
    {
        return ['rejected' => ['rejected'], 'hired' => ['hired']];
    }

    public static function protectedEndpoints(): array
    {
        return [
            'staff options' => ['GET', '/api/v1/recruitment/interviewers'],
            'staff list' => ['GET', '/api/v1/recruitment/applications/999999/interviews'],
            'staff schedule' => ['POST', '/api/v1/recruitment/applications/999999/interviews'],
            'staff lobby' => ['GET', '/api/v1/recruitment/interviews/999999'],
            'staff join' => ['POST', '/api/v1/recruitment/interviews/999999/join'],
            'staff signal' => ['POST', '/api/v1/recruitment/interviews/999999/signal'],
            'staff cancel' => ['POST', '/api/v1/recruitment/interviews/999999/cancel'],
            'staff complete' => ['POST', '/api/v1/recruitment/interviews/999999/complete'],
            'staff broadcast auth' => ['POST', '/api/v1/recruitment/interviews/broadcasting/auth'],
            'applicant list' => ['GET', '/api/v1/applicant/applications/999999/interviews'],
            'applicant lobby' => ['GET', '/api/v1/applicant/interviews/999999'],
            'applicant join' => ['POST', '/api/v1/applicant/interviews/999999/join'],
            'applicant signal' => ['POST', '/api/v1/applicant/interviews/999999/signal'],
            'applicant broadcast auth' => ['POST', '/api/v1/applicant/interviews/broadcasting/auth'],
        ];
    }

    private function fixtureBroadcaster(): void
    {
        $channels = Broadcast::getChannels();
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'interview-public', 'broadcasting.connections.reverb.secret' => 'interview-secret', 'broadcasting.connections.reverb.app_id' => 'interview-fixture']);
        foreach ($channels as $name => $callback) {
            Broadcast::channel($name, $callback);
        }
    }
}
