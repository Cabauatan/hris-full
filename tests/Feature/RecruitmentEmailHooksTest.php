<?php

namespace Tests\Feature;

use App\Events\InterviewEnded;
use App\Mail\HrisUpdateMail;
use App\Models\Applicant;
use App\Models\ApplicantExamAttempt;
use App\Models\EmailDelivery;
use App\Models\JobApplication;
use App\Models\JobOpening;
use App\Models\Permission;
use App\Models\RecruitmentExam;
use App\Models\RecruitmentInterview;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\ApplicantAuthenticationService;
use App\Services\Notification\EmailNotificationService;
use App\Services\Recruitment\RecruitmentInterviewService;
use App\Services\Recruitment\RecruitmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\FormRequestTestCase;

class RecruitmentEmailHooksTest extends FormRequestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['hris_mail.enabled' => true, 'hris_mail.frontend_url' => 'http://127.0.0.1:5002', 'queue.default' => 'database', 'mail.default' => 'array']);
    }

    public function test_registration_queues_a_welcome_for_only_the_new_applicant_without_credentials(): void
    {
        $staff = $this->user();
        Mail::fake();

        $response = $this->postJson('/api/v1/applicant/auth/register', self::registrationInput() + ['employee_number' => 'SPOOFED']);

        $response->assertCreated();
        $applicant = Applicant::query()->findOrFail($response->json('data.id'));
        $this->assertEmailQueued('applicant', $applicant->id, 'registered', $applicant);
        $this->assertDatabaseCount('email_deliveries', 1);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('employees', 0);
        $payload = DB::table('jobs')->value('payload');
        $this->assertStringNotContainsString('candidate-password', $payload);
        $this->assertStringNotContainsString($applicant->email, $payload);
        $this->assertStringNotContainsString($staff->email, $payload);
        Mail::assertNothingSent();
    }

    #[TestWith(['invalid'])]
    #[TestWith(['duplicate'])]
    public function test_unsuccessful_registration_queues_no_email(string $scenario): void
    {
        $payload = self::registrationInput();
        if ($scenario === 'duplicate') {
            Applicant::factory()->create(['email' => $payload['email']]);
        } else {
            $payload['password_confirmation'] = 'does-not-match';
        }
        Mail::fake();

        $this->postJson('/api/v1/applicant/auth/register', $payload)->assertUnprocessable();

        $this->assertDatabaseCount('applicants', $scenario === 'duplicate' ? 1 : 0);
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertDatabaseCount('jobs', 0);
        Mail::assertNothingSent();
    }

    public function test_registration_outer_rollback_discards_the_account_welcome_and_after_commit_job(): void
    {
        Mail::fake();
        $request = Request::create('/api/v1/applicant/auth/register', 'POST');
        $request->setLaravelSession(app('session')->driver());

        try {
            DB::transaction(function () use ($request): void {
                app(ApplicantAuthenticationService::class)->register($request, self::registrationInput());
                $this->assertDatabaseCount('email_deliveries', 1);
                $this->assertDatabaseCount('jobs', 0);
                throw new RuntimeException('Fixture rollback.');
            });
            $this->fail('Expected the outer registration transaction to roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fixture rollback.', $exception->getMessage());
        } finally {
            auth('applicant')->logout();
        }

        $this->assertDatabaseCount('applicants', 0);
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertDatabaseCount('jobs', 0);
        Mail::assertNothingSent();
    }

    public function test_submission_notifies_the_owner_and_current_direct_or_role_managers_once(): void
    {
        $direct = $this->user('recruitment.view', 'recruitment.manage');
        $roleManager = $this->user();
        $role = Role::query()->create(['code' => 'recruitment_hr', 'name' => 'Recruitment HR', 'is_active' => true]);
        $role->permissions()->attach(Permission::query()->whereIn('code', ['recruitment.view', 'recruitment.manage'])->pluck('id'));
        $roleManager->roles()->attach($role);
        $direct->roles()->attach($role);
        $this->user('recruitment.view');
        $this->user('recruitment.manage');
        $this->user('recruitment.view', 'recruitment.manage')->update(['is_active' => false]);
        $inactiveRoleManager = $this->user();
        $inactiveRole = Role::query()->create(['code' => 'inactive_recruitment', 'name' => 'Inactive recruitment', 'is_active' => false]);
        $inactiveRole->permissions()->attach($role->permissions()->pluck('permissions.id'));
        $inactiveRoleManager->roles()->attach($inactiveRole);
        $applicant = Applicant::factory()->create(['id' => $direct->id, 'email' => $direct->email]);
        $opening = JobOpening::factory()->create(['title' => 'HR Officer', 'monthly_salary' => '35000.50']);
        Mail::fake();

        $application = app(RecruitmentService::class)->apply($applicant, ['job_opening_id' => $opening->id]);

        $this->assertEmailQueued('job_application', $application->id, 'submitted', $applicant);
        $this->assertEmailQueued('job_application', $application->id, 'submitted', $direct);
        $this->assertEmailQueued('job_application', $application->id, 'submitted', $roleManager);
        $this->assertDatabaseCount('email_deliveries', 3);
        $this->assertDatabaseCount('jobs', 3);
        $this->assertDatabaseHas('job_applications', ['id' => $application->id, 'desired_position' => 'HR Officer', 'monthly_salary' => '35000.50', 'status' => 'pending']);
        Mail::assertNothingSent();
    }

    #[TestWith(['duplicate'])]
    #[TestWith(['closed'])]
    #[TestWith(['inactive'])]
    public function test_failed_application_creates_no_delivery_or_job(string $scenario): void
    {
        $applicant = Applicant::factory()->create();
        $opening = JobOpening::factory()->create();
        if ($scenario === 'duplicate') {
            JobApplication::factory()->for($applicant, 'applicant')->create();
        } elseif ($scenario === 'closed') {
            $opening->update(['status' => 'closed']);
        } else {
            $applicant->update(['is_active' => false]);
        }
        Mail::fake();

        try {
            app(RecruitmentService::class)->apply($applicant, ['job_opening_id' => $opening->id]);
            $this->fail('Expected the application to be rejected.');
        } catch (RuntimeException|ValidationException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        $this->assertDatabaseCount('job_applications', $scenario === 'duplicate' ? 1 : 0);
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertDatabaseCount('jobs', 0);
        Mail::assertNothingSent();
    }

    #[TestWith(['approve', 'pending', 'approved'])]
    #[TestWith(['reject', 'approved', 'rejected'])]
    #[TestWith(['hire', 'approved', 'hired'])]
    public function test_application_transition_notifies_only_the_owner_and_repeated_action_adds_nothing(string $action, string $initialStatus, string $event): void
    {
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $application = JobApplication::factory()->create(['status' => $initialStatus, 'monthly_salary' => '25000.75']);
        $service = app(RecruitmentService::class);
        Mail::fake();

        $changed = $action === 'hire'
            ? $service->hire($application, $staff, '2026-12-01')
            : $service->{$action}($application, $staff, 'Private review details.');

        $this->assertSame($event, $changed->status);
        $this->assertEmailQueued('job_application', $application->id, $event, $application->applicant);
        $this->assertDatabaseCount('email_deliveries', 1);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('employees', 0);
        $this->assertSame('25000.75', $changed->monthly_salary);
        if ($action === 'hire') {
            $this->assertSame('2026-12-01', $changed->start_date->toDateString());
        }
        try {
            $action === 'hire' ? $service->hire($application, $staff, '2026-12-02') : $service->{$action}($application, $staff);
            $this->fail('Expected a repeated application transition to fail.');
        } catch (RuntimeException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertDatabaseCount('email_deliveries', 1);
        $this->assertDatabaseCount('jobs', 1);
        Mail::assertNothingSent();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_assignment_notifies_once_and_only_fully_automatic_submission_publishes_a_result(bool $mixed): void
    {
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $application = JobApplication::factory()->approved()->create();
        $exam = RecruitmentExam::factory()->create();
        if (! $mixed) {
            $exam->update(['questions' => [$exam->questions[0]], 'max_score' => 5]);
        }
        $service = app(RecruitmentService::class);
        Mail::fake();

        $attempt = $service->assign($application, $exam, $staff);
        $this->assertEmailQueued('applicant_exam_attempt', $attempt->id, 'assigned', $application->applicant);
        try {
            $service->assign($application, $exam, $staff);
            $this->fail('Expected repeated exam assignment to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('This exam has already been assigned to the application.', $exception->getMessage());
        }
        $submitted = $service->submit($attempt, $application->applicant, $this->answers($attempt));

        $this->assertSame($mixed ? 'submitted' : 'graded', $submitted->status);
        $this->assertDatabaseCount('applicant_exam_attempts', 1);
        $this->assertDatabaseCount('email_deliveries', $mixed ? 1 : 2);
        $this->assertDatabaseCount('jobs', $mixed ? 1 : 2);
        if ($mixed) {
            $this->assertNull($submitted->score);
            $this->assertDatabaseMissing('email_deliveries', ['resource_type' => 'applicant_exam_attempt', 'resource_id' => $attempt->id, 'event' => 'result_ready']);
        } else {
            $this->assertSame('5.00', $submitted->score);
            $this->assertSame('100.00', $submitted->percentage);
            $this->assertTrue($submitted->passed);
            $this->assertEmailQueued('applicant_exam_attempt', $attempt->id, 'result_ready', $application->applicant);
        }
        try {
            $service->submit($attempt, $application->applicant, $this->answers($attempt));
            $this->fail('Expected repeated exam submission to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('This exam has already been submitted.', $exception->getMessage());
        }
        $this->assertDatabaseCount('email_deliveries', $mixed ? 1 : 2);
        Mail::assertNothingSent();
    }

    public function test_manual_grade_publishes_the_final_result_once_and_omits_written_answers_and_feedback(): void
    {
        $staff = $this->user();
        $attempt = ApplicantExamAttempt::factory()->create();
        $service = app(RecruitmentService::class);
        $service->submit($attempt, $attempt->application->applicant, $this->answers($attempt));
        Mail::fake();

        $graded = $service->grade($attempt, $staff, [['question_id' => $attempt->questions[1]['id'], 'score' => '8.50', 'feedback' => 'Private grading feedback.']]);

        $this->assertSame('13.50', $graded->score);
        $this->assertSame('90.00', $graded->percentage);
        $this->assertEmailQueued('applicant_exam_attempt', $attempt->id, 'result_ready', $attempt->application->applicant);
        $this->assertDatabaseCount('email_deliveries', 1);
        $this->assertDatabaseCount('jobs', 1);
        try {
            $service->grade($attempt, $staff, [['question_id' => $attempt->questions[1]['id'], 'score' => 1]]);
            $this->fail('Expected a graded attempt to reject repeat grading.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Only submitted exams awaiting manual grading can be graded.', $exception->getMessage());
        }
        $this->deliverQueuedEmails();
        Mail::assertSent(HrisUpdateMail::class, function (HrisUpdateMail $mail) use ($attempt): bool {
            $this->assertStringNotContainsString('Private grading feedback.', $mail->bodyText);
            $this->assertStringNotContainsString('Written answer details.', $mail->bodyText);

            return $mail->hasTo($attempt->application->applicant->email)
                && $mail->actionUrl === 'http://127.0.0.1:5002/applicant/exams/'.$attempt->id;
        });
        Mail::assertSentCount(1);
        $this->assertDatabaseCount('email_deliveries', 1);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_failed_manual_grade_keeps_result_private_and_queues_nothing(bool $missingQuestion): void
    {
        $staff = $this->user();
        $attempt = ApplicantExamAttempt::factory()->create();
        $service = app(RecruitmentService::class);
        $service->submit($attempt, $attempt->application->applicant, $this->answers($attempt));
        Mail::fake();
        $grades = $missingQuestion ? [] : [['question_id' => $attempt->questions[1]['id'], 'score' => 11]];

        try {
            $service->grade($attempt, $staff, $grades);
            $this->fail('Expected invalid written grades to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('grades', $exception->errors());
        }

        $this->assertDatabaseHas('applicant_exam_attempts', ['id' => $attempt->id, 'status' => 'submitted', 'score' => null, 'graded_at' => null]);
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertDatabaseCount('jobs', 0);
        Mail::assertNothingSent();
    }

    public function test_invalid_answers_do_not_publish_a_result_or_change_the_assigned_attempt(): void
    {
        $attempt = ApplicantExamAttempt::factory()->create();
        Mail::fake();

        try {
            app(RecruitmentService::class)->submit($attempt, $attempt->application->applicant, []);
            $this->fail('Expected missing exam answers to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('answers', $exception->errors());
        }

        $this->assertDatabaseHas('applicant_exam_attempts', ['id' => $attempt->id, 'status' => 'assigned', 'answers' => null]);
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertDatabaseCount('jobs', 0);
        Mail::assertNothingSent();
    }

    #[TestWith(['initial', 1])]
    #[TestWith(['final', 4])]
    public function test_interview_invites_include_the_applicant_and_attached_panel_with_exact_schedule_version(string $round, int $panelSize): void
    {
        $this->travelTo('2026-10-06 10:00:00');
        $panel = [];
        for ($i = 0; $i < $panelSize; $i++) {
            $panel[] = $this->user('recruitment.view', 'recruitment.manage');
        }
        $applicant = Applicant::factory()->create(['id' => $panel[0]->id, 'email' => $panel[0]->email]);
        $application = JobApplication::factory()->approved()->for($applicant, 'applicant')->create();
        if ($round === 'final') {
            RecruitmentInterview::factory()->for($application, 'application')->create(['status' => 'completed']);
        }
        Mail::fake();

        $interview = app(RecruitmentInterviewService::class)->schedule($application, $panel[0], [
            'round' => $round, 'scheduled_at' => '2026-10-07T01:00:00Z', 'duration_minutes' => 30,
            'interviewer_ids' => array_map(static fn (User $user): int => $user->id, $panel), 'private_notes' => 'Private interview assessment.',
        ]);

        $this->assertEmailQueued('recruitment_interview', $interview->id, 'scheduled', $applicant, '2026-10-07 09:00:00');
        foreach ($panel as $interviewer) {
            $this->assertEmailQueued('recruitment_interview', $interview->id, 'scheduled', $interviewer, '2026-10-07 09:00:00');
        }
        $this->assertDatabaseCount('recruitment_interview_user', $panelSize);
        $this->assertDatabaseCount('email_deliveries', $panelSize + 1);
        $this->assertDatabaseCount('jobs', $panelSize + 1);
        $this->deliverQueuedEmails();
        Mail::assertSent(HrisUpdateMail::class, fn (HrisUpdateMail $mail): bool => $mail->hasTo($applicant->email)
            && $mail->actionUrl === 'http://127.0.0.1:5002/applicant/interviews/'.$interview->id);
        foreach ($panel as $interviewer) {
            Mail::assertSent(HrisUpdateMail::class, function (HrisUpdateMail $mail) use ($interviewer, $interview): bool {
                $this->assertStringNotContainsString('Private interview assessment.', $mail->bodyText);

                return $mail->hasTo($interviewer->email) && $mail->actionUrl === 'http://127.0.0.1:5002/recruitment/interviews/'.$interview->id;
            });
        }
        Mail::assertSentCount($panelSize + 1);
    }

    #[TestWith(['past'])]
    #[TestWith(['duplicate_panel'])]
    #[TestWith(['unauthorized_panel'])]
    #[TestWith(['final_without_initial'])]
    public function test_failed_interview_scheduling_creates_no_invites_or_panel_rows(string $scenario): void
    {
        $this->travelTo('2026-10-06 10:00:00');
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $application = JobApplication::factory()->approved()->create();
        $input = ['round' => 'initial', 'scheduled_at' => '2026-10-07T09:00:00+08:00', 'duration_minutes' => 30, 'interviewer_ids' => [$staff->id]];
        if ($scenario === 'past') {
            $input['scheduled_at'] = '2026-10-05T09:00:00+08:00';
        } elseif ($scenario === 'duplicate_panel') {
            $input['interviewer_ids'][] = $staff->id;
        } elseif ($scenario === 'unauthorized_panel') {
            $input['interviewer_ids'] = [$this->user()->id];
        } else {
            $input['round'] = 'final';
        }
        Mail::fake();

        try {
            app(RecruitmentInterviewService::class)->schedule($application, $staff, $input);
            $this->fail('Expected the invalid interview schedule to fail.');
        } catch (RuntimeException|ValidationException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        $this->assertDatabaseCount('recruitment_interviews', 0);
        $this->assertDatabaseCount('recruitment_interview_user', 0);
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertDatabaseCount('jobs', 0);
        Mail::assertNothingSent();
    }

    #[TestWith(['cancel', 'cancelled', 2])]
    #[TestWith(['complete', 'completed', 0])]
    public function test_interview_finish_sends_cancellation_only_and_never_duplicates_on_repeat(string $action, string $status, int $deliveryCount): void
    {
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $interview = RecruitmentInterview::factory()->hasAttached($staff, [], 'interviewers')->create(['scheduled_at' => '2026-10-07 09:00:00']);
        Event::fake([InterviewEnded::class]);
        Mail::fake();
        $service = app(RecruitmentInterviewService::class);

        $service->{$action}($interview, $staff, 'Private conclusion.');

        $this->assertSame($status, $interview->refresh()->status);
        $this->assertDatabaseCount('email_deliveries', $deliveryCount);
        $this->assertDatabaseCount('jobs', $deliveryCount);
        if ($action === 'cancel') {
            $this->assertEmailQueued('recruitment_interview', $interview->id, 'cancelled', $interview->application->applicant, '2026-10-07 09:00:00');
            $this->assertEmailQueued('recruitment_interview', $interview->id, 'cancelled', $staff, '2026-10-07 09:00:00');
        }
        try {
            $service->{$action}($interview, $staff);
            $this->fail('Expected a completed or cancelled interview to reject repeated finishing.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Only scheduled interviews can be completed or cancelled.', $exception->getMessage());
        }
        $this->assertDatabaseCount('email_deliveries', $deliveryCount);
        $this->assertDatabaseCount('jobs', $deliveryCount);
        Event::assertDispatchedTimes(InterviewEnded::class, 1);
        Mail::assertNothingSent();
    }

    public function test_rejection_automatically_notifies_each_cancelled_interview_and_its_current_panel(): void
    {
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $second = $this->user('recruitment.view', 'recruitment.manage');
        $inactive = $this->user('recruitment.view', 'recruitment.manage');
        $inactive->update(['is_active' => false]);
        $application = JobApplication::factory()->approved()->create();
        $initial = RecruitmentInterview::factory()->for($application, 'application')->hasAttached([$staff, $inactive], [], 'interviewers')->create();
        $final = RecruitmentInterview::factory()->finalRound()->for($application, 'application')->hasAttached([$staff, $second], [], 'interviewers')->create();
        $history = RecruitmentInterview::factory()->for($application, 'application')->create(['status' => 'completed']);
        Event::fake([InterviewEnded::class]);
        Mail::fake();

        app(RecruitmentService::class)->reject($application, $staff, 'Private rejection assessment.');

        $this->assertEmailQueued('job_application', $application->id, 'rejected', $application->applicant);
        foreach ([$initial, $final] as $interview) {
            $this->assertSame('cancelled', $interview->refresh()->status);
            $this->assertEmailQueued('recruitment_interview', $interview->id, 'cancelled', $application->applicant, $interview->scheduled_at->format('Y-m-d H:i:s'));
            $this->assertEmailQueued('recruitment_interview', $interview->id, 'cancelled', $staff, $interview->scheduled_at->format('Y-m-d H:i:s'));
        }
        $this->assertEmailQueued('recruitment_interview', $final->id, 'cancelled', $second, $final->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseMissing('email_deliveries', ['recipient_type' => 'user', 'recipient_id' => $inactive->id]);
        $this->assertDatabaseMissing('email_deliveries', ['resource_type' => 'recruitment_interview', 'resource_id' => $history->id]);
        $this->assertSame('completed', $history->refresh()->status);
        $this->assertDatabaseCount('email_deliveries', 6);
        $this->assertDatabaseCount('jobs', 6);
        Event::assertDispatchedTimes(InterviewEnded::class, 2);
        $this->deliverQueuedEmails();
        Mail::assertSentCount(6);
        Mail::assertSent(HrisUpdateMail::class, fn (HrisUpdateMail $mail): bool => $mail->hasTo($application->applicant->email)
            && $mail->title === 'Recruitment interview cancelled');
    }

    public function test_cancelled_schedule_skips_stale_invites_and_delivers_only_rejection_and_cancellation(): void
    {
        $this->travelTo('2026-10-06 10:00:00');
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $application = JobApplication::factory()->approved()->create();
        Event::fake([InterviewEnded::class]);
        Mail::fake();
        $interview = app(RecruitmentInterviewService::class)->schedule($application, $staff, [
            'round' => 'initial', 'scheduled_at' => '2026-10-07T09:00:00+08:00', 'duration_minutes' => 30, 'interviewer_ids' => [$staff->id],
        ]);
        app(RecruitmentService::class)->reject($application, $staff);

        $this->deliverQueuedEmails();

        $this->assertDatabaseCount('email_deliveries', 5);
        $this->assertSame(2, EmailDelivery::query()->where('resource_id', $interview->id)->where('resource_type', 'recruitment_interview')->where('event', 'scheduled')->where('status', 'skipped')->count());
        Mail::assertNotSent(HrisUpdateMail::class, fn (HrisUpdateMail $mail): bool => $mail->title === 'Recruitment interview scheduled');
        Mail::assertSentCount(3);
        Event::assertDispatchedTimes(InterviewEnded::class, 1);
    }

    public function test_nested_recruitment_rollback_discards_all_transition_mail_and_domain_writes(): void
    {
        $this->travelTo('2026-10-06 10:00:00');
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $application = JobApplication::factory()->create();
        $exam = RecruitmentExam::factory()->create();
        Event::fake([InterviewEnded::class]);
        Mail::fake();

        try {
            DB::transaction(function () use ($staff, $application, $exam): void {
                $service = app(RecruitmentService::class);
                $service->approve($application, $staff);
                $service->assign($application, $exam, $staff);
                app(RecruitmentInterviewService::class)->schedule($application, $staff, [
                    'round' => 'initial', 'scheduled_at' => '2026-10-07T09:00:00+08:00', 'duration_minutes' => 30, 'interviewer_ids' => [$staff->id],
                ]);
                $service->reject($application, $staff);
                $this->assertDatabaseCount('email_deliveries', 7);
                $this->assertDatabaseCount('jobs', 0);
                throw new RuntimeException('Fixture rollback.');
            });
            $this->fail('Expected the outer recruitment transaction to roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fixture rollback.', $exception->getMessage());
        }

        $this->assertDatabaseHas('job_applications', ['id' => $application->id, 'status' => 'pending', 'reviewed_at' => null]);
        $this->assertDatabaseCount('applicant_exam_attempts', 0);
        $this->assertDatabaseCount('recruitment_interviews', 0);
        $this->assertDatabaseCount('recruitment_interview_user', 0);
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertDatabaseCount('jobs', 0);
        Event::assertNotDispatched(InterviewEnded::class);
        Mail::assertNothingSent();
    }

    public function test_committed_application_enqueues_after_the_outer_commit_and_can_deliver(): void
    {
        $applicant = Applicant::factory()->create();
        $opening = JobOpening::factory()->create();
        Mail::fake();

        DB::transaction(function () use ($applicant, $opening): void {
            app(RecruitmentService::class)->apply($applicant, ['job_opening_id' => $opening->id]);
            $this->assertDatabaseCount('email_deliveries', 1);
            $this->assertDatabaseCount('jobs', 0);
            Mail::assertNothingSent();
        });

        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame('notifications', DB::table('jobs')->value('queue'));
        $this->deliverQueuedEmails();
        Mail::assertSent(HrisUpdateMail::class, fn (HrisUpdateMail $mail): bool => $mail->hasTo($applicant->email)
            && $mail->title === 'Job application submitted' && $mail->actionUrl === 'http://127.0.0.1:5002/applicant/applications');
        Mail::assertSentCount(1);
    }

    public function test_disabled_mail_preserves_recruitment_behavior_without_ledger_or_jobs(): void
    {
        config(['hris_mail.enabled' => false]);
        $applicant = Applicant::factory()->create();
        $opening = JobOpening::factory()->create();
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        Mail::fake();

        $application = app(RecruitmentService::class)->apply($applicant, ['job_opening_id' => $opening->id]);
        app(RecruitmentService::class)->approve($application, $staff);

        $this->assertSame('approved', $application->refresh()->status);
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertDatabaseCount('jobs', 0);
        Mail::assertNothingSent();
    }

    private function assertEmailQueued(string $type, int $id, string $event, User|Applicant $recipient, ?string $version = null): void
    {
        $this->assertDatabaseHas('email_deliveries', [
            'resource_type' => $type, 'resource_id' => $id, 'event' => $event,
            'event_id' => 'hris/recruitment/'.$type.'/'.$id.'/'.$event,
            'recipient_type' => $recipient instanceof Applicant ? 'applicant' : 'user', 'recipient_id' => $recipient->id,
            'source_version' => $version, 'status' => 'queued', 'sent_at' => null,
        ]);
    }

    private function deliverQueuedEmails(): void
    {
        foreach (EmailDelivery::query()->where('status', 'queued')->pluck('id') as $id) {
            app(EmailNotificationService::class)->deliver($id);
        }
    }

    /** @return list<array{question_id: string, answer: string}> */
    private function answers(ApplicantExamAttempt $attempt): array
    {
        return array_map(static fn (array $question): array => [
            'question_id' => $question['id'],
            'answer' => $question['type'] === 'multiple_choice' ? '0' : 'Written answer details.',
        ], $attempt->questions);
    }

    /** @return array<string, string> */
    private static function registrationInput(): array
    {
        return ['first_name' => 'External', 'last_name' => 'Applicant', 'email' => 'candidate@example.test', 'password' => 'candidate-password', 'password_confirmation' => 'candidate-password'];
    }
}
