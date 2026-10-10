<?php

namespace Tests\Feature;

use App\Events\InterviewEnded;
use App\Events\InterviewSignal;
use App\Models\Applicant;
use App\Models\JobApplication;
use App\Models\RecruitmentInterview;
use App\Services\Recruitment\RecruitmentInterviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\FormRequestTestCase;

class RecruitmentInterviewServiceTest extends FormRequestTestCase
{
    public function test_schedule_normalizes_offset_attaches_the_panel_and_ignores_client_status_uuid_and_parent(): void
    {
        $this->travelTo('2026-10-06 10:00:00');
        $staff = $this->user('recruitment.manage');
        $application = JobApplication::factory()->approved()->create();

        $interview = app(RecruitmentInterviewService::class)->schedule($application, $staff, [
            'round' => 'initial', 'scheduled_at' => '2026-10-07T01:00:00Z', 'duration_minutes' => 45,
            'interviewer_ids' => [$staff->id], 'private_notes' => 'Private details.',
            'status' => 'completed', 'uuid' => 'client-owned', 'job_application_id' => 999,
        ]);

        $this->assertDatabaseHas('recruitment_interviews', ['id' => $interview->id, 'job_application_id' => $application->id, 'status' => 'scheduled', 'scheduled_at' => '2026-10-07 09:00:00', 'duration_minutes' => 45]);
        $this->assertNotSame('client-owned', $interview->uuid);
        $this->assertSame([$staff->id], $interview->interviewers()->pluck('users.id')->all());
        $this->assertDatabaseCount('employees', 0);
    }

    #[DataProvider('invalidSchedules')]
    public function test_invalid_schedule_time_has_no_partial_interview_or_panel_writes(string $value): void
    {
        $this->travelTo('2026-10-06 10:00:00');
        $staff = $this->user('recruitment.manage');
        $application = JobApplication::factory()->approved()->create();
        try {
            app(RecruitmentInterviewService::class)->schedule($application, $staff, ['round' => 'initial', 'scheduled_at' => $value, 'duration_minutes' => 30, 'interviewer_ids' => [$staff->id]]);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scheduled_at', $exception->errors());
            $this->assertDatabaseCount('recruitment_interviews', 0);
            $this->assertDatabaseCount('recruitment_interview_user', 0);

            return;
        }
        $this->fail('Expected the invalid interview schedule to be rejected.');
    }

    public function test_duplicate_active_round_is_blocked_but_cancelled_history_can_be_replaced(): void
    {
        $this->travelTo('2026-10-06 10:00:00');
        $staff = $this->user('recruitment.manage');
        $application = JobApplication::factory()->approved()->create();
        $original = RecruitmentInterview::factory()->for($application, 'application')->create();
        Event::fake([InterviewEnded::class]);
        $input = ['round' => 'initial', 'scheduled_at' => '2026-10-07T09:00:00+08:00', 'duration_minutes' => 30, 'interviewer_ids' => [$staff->id]];
        try {
            app(RecruitmentInterviewService::class)->schedule($application, $staff, $input);
            $this->fail('Expected the duplicate scheduled round to be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('This application already has a scheduled interview for this round.', $exception->getMessage());
        }

        app(RecruitmentInterviewService::class)->cancel($original, $staff);
        $replacement = app(RecruitmentInterviewService::class)->schedule($application, $staff, $input);

        $this->assertNotSame($original->uuid, $replacement->uuid);
        $this->assertDatabaseCount('recruitment_interviews', 2);
        Event::assertDispatchedTimes(InterviewEnded::class, 1);
    }

    public function test_final_round_requires_a_completed_initial_interview(): void
    {
        $this->travelTo('2026-10-06 10:00:00');
        $staff = $this->user('recruitment.manage');
        $application = JobApplication::factory()->approved()->create();
        $initial = RecruitmentInterview::factory()->for($application, 'application')->create();
        Event::fake([InterviewEnded::class]);
        $input = ['round' => 'final', 'scheduled_at' => '2026-10-07T09:00:00+08:00', 'duration_minutes' => 30, 'interviewer_ids' => [$staff->id]];
        try {
            app(RecruitmentInterviewService::class)->schedule($application, $staff, $input);
            $this->fail('Expected final scheduling to require initial completion.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Complete the initial interview before scheduling the final interview.', $exception->getMessage());
        }

        app(RecruitmentInterviewService::class)->complete($initial, $staff, 'Staff outcome.');
        $final = app(RecruitmentInterviewService::class)->schedule($application, $staff, $input);

        $this->assertSame('final', $final->round);
        $this->assertDatabaseHas('recruitment_interviews', ['id' => $initial->id, 'status' => 'completed', 'completed_by' => $staff->id, 'private_notes' => 'Staff outcome.']);
        Event::assertDispatched(InterviewEnded::class, fn (InterviewEnded $event): bool => $event->broadcastWith() === ['interview_uuid' => $initial->uuid, 'status' => 'completed']);
    }

    #[DataProvider('joiningWindows')]
    public function test_room_window_includes_its_exact_boundaries_and_rejects_outside_them(string $now, bool $allowed): void
    {
        $this->travelTo($now);
        $applicant = Applicant::factory()->create();
        $application = JobApplication::factory()->approved()->for($applicant, 'applicant')->create();
        $interview = RecruitmentInterview::factory()->for($application, 'application')->create(['scheduled_at' => '2026-10-07 09:00:00']);

        $this->assertSame($allowed, app(RecruitmentInterviewService::class)->canJoin($interview, $applicant));
    }

    public function test_join_and_signal_recheck_fresh_panel_ownership_and_application_state(): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $staff = $this->user('recruitment.manage');
        $interview = RecruitmentInterview::factory()->hasAttached($staff, [], 'interviewers')->create(['scheduled_at' => now()]);
        $interview->interviewers()->detach($staff);
        Event::fake([InterviewSignal::class]);
        try {
            app(RecruitmentInterviewService::class)->signal($interview, $staff, ['type' => 'ready']);
            $this->fail('Expected fresh panel membership to be required.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        Event::assertNotDispatched(InterviewSignal::class);
        $this->assertNull($interview->refresh()->started_at);
    }

    public function test_room_roster_distinguishes_staff_and_applicant_with_the_same_numeric_id(): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $staff = $this->user('recruitment.manage');
        $interview = RecruitmentInterview::factory()->hasAttached($staff, [], 'interviewers')->create(['scheduled_at' => now()]);
        $applicant = $interview->application->applicant;
        $this->assertSame($staff->id, $applicant->id);

        $room = app(RecruitmentInterviewService::class)->join($interview, $applicant);

        $this->assertSame('applicant:'.$applicant->id, $room['actor']['key']);
        $this->assertSame(['applicant:'.$applicant->id, 'user:'.$staff->id], array_column($room['participants'], 'key'));
        $this->assertDatabaseHas('recruitment_interviews', ['id' => $interview->id, 'started_at' => '2026-10-07 09:00:00']);
    }

    public function test_signals_are_server_owned_private_and_do_not_persist_signaling_content(): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $staff = $this->user('recruitment.manage');
        $interview = RecruitmentInterview::factory()->hasAttached($staff, [], 'interviewers')->create(['scheduled_at' => now()]);
        $applicant = $interview->application->applicant;
        $before = $interview->refresh()->getAttributes();
        Event::fake([InterviewSignal::class]);

        app(RecruitmentInterviewService::class)->signal($interview, $staff, ['type' => 'offer', 'target_key' => 'applicant:'.$applicant->id, 'payload' => ['sdp' => 'fixture-sdp', 'channel' => 'arbitrary'], 'sender' => ['key' => 'spoofed']]);

        $this->assertSame($before, $interview->refresh()->getAttributes());
        Event::assertDispatched(InterviewSignal::class, function (InterviewSignal $event) use ($interview, $staff, $applicant): bool {
            $this->assertSame('private-Recruitment.Interview.'.$interview->uuid, (string) $event->broadcastOn()[0]);
            $this->assertSame('interview.signal', $event->broadcastAs());
            $this->assertSame('user:'.$staff->id, $event->sender['key']);
            $this->assertSame('applicant:'.$applicant->id, $event->targetKey);
            $this->assertSame(['sdp' => 'fixture-sdp'], $event->payload);

            return true;
        });
    }

    public function test_rolled_back_outer_transaction_does_not_dispatch_an_interview_end_event(): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $staff = $this->user('recruitment.manage');
        $interview = RecruitmentInterview::factory()->create();
        Event::fake([InterviewEnded::class]);
        try {
            DB::transaction(function () use ($interview, $staff): void {
                app(RecruitmentInterviewService::class)->complete($interview, $staff);
                throw new RuntimeException('Fixture rollback.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Fixture rollback.', $exception->getMessage());
        }

        $this->assertSame('scheduled', $interview->refresh()->status);
        Event::assertNotDispatched(InterviewEnded::class);
    }

    public static function invalidSchedules(): array
    {
        return ['no timezone' => ['2026-10-07T09:00:00'], 'invalid date' => ['2026-02-30T09:00:00+08:00'], 'past' => ['2026-10-05T09:00:00+08:00']];
    }

    public static function joiningWindows(): array
    {
        return ['early' => ['2026-10-07 08:44:59', false], 'opening' => ['2026-10-07 08:45:00', true], 'closing' => ['2026-10-07 10:00:00', true], 'late' => ['2026-10-07 10:00:01', false]];
    }
}
