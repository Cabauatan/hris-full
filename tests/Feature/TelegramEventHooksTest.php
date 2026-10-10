<?php

namespace Tests\Feature;

use App\Events\InterviewEnded;
use App\Jobs\DeliverHrisEmail;
use App\Jobs\DeliverHrisTelegram;
use App\Mail\HrisUpdateMail;
use App\Models\Applicant;
use App\Models\EmailDelivery;
use App\Models\JobApplication;
use App\Models\JobOpening;
use App\Models\RecruitmentExam;
use App\Models\TelegramConnection;
use App\Models\TelegramDelivery;
use App\Services\Cto\CtoService;
use App\Services\Notification\OutboundNotificationService;
use App\Services\Recruitment\RecruitmentInterviewService;
use App\Services\Recruitment\RecruitmentService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\FormRequestTestCase;

class TelegramEventHooksTest extends FormRequestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'telegram.enabled' => true, 'telegram.bot_token' => '12345:events_token',
            'telegram.bot_username' => 'example_bot', 'telegram.bot_id' => 12345,
            'telegram.frontend_url' => 'https://hris.example.test',
            'hris_mail.frontend_url' => 'https://hris.example.test', 'queue.default' => 'database',
        ]);
    }

    public function test_password_change_uses_one_event_for_email_and_telegram_without_serializing_credentials(): void
    {
        config(['hris_mail.enabled' => true]);
        $user = $this->user();
        $this->employee($user);
        TelegramConnection::factory()->connected('777')->create(['recipient_id' => $user->id]);
        Mail::fake();
        $this->fakeTelegram();

        $this->actingAs($user)->putJson('/api/v1/auth/password', [
            'current_password' => 'password', 'password' => 'changed-password-2026',
            'password_confirmation' => 'changed-password-2026',
        ])->assertOk();

        $this->assertTrue(Hash::check('changed-password-2026', $user->fresh()->password));
        $email = EmailDelivery::query()->sole();
        $telegram = TelegramDelivery::query()->sole();
        $this->assertTrue(Str::isUuid($email->event_id));
        $this->assertSame($email->event_id, $telegram->event_id);
        $this->assertSame('password_changed', $telegram->event);
        $this->assertDatabaseCount('jobs', 2);
        foreach (DB::table('jobs')->pluck('payload') as $payload) {
            $this->assertStringNotContainsString('changed-password-2026', $payload);
            $this->assertStringNotContainsString($user->fresh()->password, $payload);
            $this->assertStringNotContainsString('12345:events_token', $payload);
            $this->assertStringNotContainsString('chat_id', $payload);
        }
        Mail::assertNothingSent();
        Http::assertNothingSent();

        $this->drainQueue();

        Mail::assertSent(HrisUpdateMail::class, fn (HrisUpdateMail $mail): bool => $mail->hasTo($user->email));
        Mail::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '777' && str_contains($request['text'], 'https://hris.example.test/profile')
            && ! str_contains($request['text'], 'changed-password-2026'));
        Http::assertSentCount(2);
        $this->assertSame('sent', $telegram->fresh()->status);
    }

    public function test_cto_submission_and_rejection_send_telegram_to_existing_recipients_with_email_disabled(): void
    {
        $reviewer = $this->user('cto.view', 'cto.approve');
        $owner = $this->user('cto.view');
        $employee = $this->employee($owner, ['supervisor_employee_id' => $this->employee($reviewer)->id]);
        TelegramConnection::factory()->connected('111')->create(['recipient_id' => $reviewer->id]);
        TelegramConnection::factory()->connected('222')->create(['recipient_id' => $owner->id]);
        Mail::fake();
        $this->fakeTelegram();
        $service = app(CtoService::class);

        $record = $service->submit($employee, $owner, '2026-10-08 08:00:00', '2026-10-08 10:00:00', 'Private reason');
        $this->drainQueue();

        $this->assertDatabaseHas('telegram_deliveries', ['recipient_type' => 'user', 'recipient_id' => $reviewer->id, 'event' => 'pending', 'status' => 'sent']);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '111' && str_contains($request['text'], '/requests/cto?record='.$record->id));
        $this->assertSame(1, $reviewer->notifications()->count());

        $service->reject($record, $reviewer, 'Private remarks');
        $this->drainQueue();

        $this->assertDatabaseHas('telegram_deliveries', ['recipient_type' => 'user', 'recipient_id' => $owner->id, 'event' => 'rejected', 'status' => 'sent']);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '222' && str_contains($request['text'], '/my/requests/cto?record='.$record->id));
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) ($request['text'] ?? ''), 'Private'));
        Http::assertSentCount(3);
        $this->assertSame(1, $owner->notifications()->count());
        $this->assertDatabaseCount('email_deliveries', 0);
        Mail::assertNothingSent();
    }

    public function test_outer_rollback_discards_password_change_and_both_channels_after_commit_jobs(): void
    {
        config(['hris_mail.enabled' => true]);
        $user = $this->user();
        $this->employee($user);
        TelegramConnection::factory()->connected()->create(['recipient_id' => $user->id]);
        Mail::fake();
        Http::preventStrayRequests();
        DB::beginTransaction();

        try {
            $this->actingAs($user)->putJson('/api/v1/auth/password', [
                'current_password' => 'password', 'password' => 'changed-password-2026',
                'password_confirmation' => 'changed-password-2026',
            ])->assertOk();
            $this->assertDatabaseCount('telegram_deliveries', 1);
            $this->assertDatabaseCount('email_deliveries', 1);
            $this->assertDatabaseCount('jobs', 0);
        } finally {
            DB::rollBack();
        }

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->assertDatabaseCount('telegram_deliveries', 0);
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertDatabaseCount('jobs', 0);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_wrong_current_password_returns_422_without_outbound_deliveries(): void
    {
        config(['hris_mail.enabled' => true]);
        $user = $this->user();
        $this->employee($user);
        TelegramConnection::factory()->connected()->create(['recipient_id' => $user->id]);
        Mail::fake();
        Http::preventStrayRequests();

        $this->actingAs($user)->putJson('/api/v1/auth/password', [
            'current_password' => 'wrong-password', 'password' => 'changed-password-2026',
            'password_confirmation' => 'changed-password-2026',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->assertDatabaseCount('telegram_deliveries', 0);
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertDatabaseCount('jobs', 0);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_recruitment_submission_approval_and_hiring_reach_linked_applicant_and_current_hr_without_email(): void
    {
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $viewer = $this->user('recruitment.view');
        $applicant = Applicant::factory()->create(['id' => $staff->id]);
        TelegramConnection::factory()->connected('111')->create(['recipient_id' => $staff->id]);
        TelegramConnection::factory()->connected('222')->create(['recipient_type' => 'applicant', 'recipient_id' => $applicant->id]);
        TelegramConnection::factory()->connected('333')->create(['recipient_id' => $viewer->id]);
        $opening = JobOpening::factory()->create(['monthly_salary' => '35000.50']);
        Mail::fake();
        $this->fakeTelegram();
        $service = app(RecruitmentService::class);

        $application = $service->apply($applicant, ['job_opening_id' => $opening->id, 'cover_letter' => 'Private cover letter']);
        $this->drainQueue();

        $this->assertSame(2, TelegramDelivery::query()->where('event', 'submitted')->where('status', 'sent')->count());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '111' && str_contains($request['text'], 'https://hris.example.test/recruitment'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '222' && str_contains($request['text'], '/applicant/applications'));

        $service->approve($application, $staff, 'Private remarks');
        $this->drainQueue();
        $service->hire($application, $staff, '2026-12-01');
        $this->drainQueue();

        $this->assertDatabaseHas('job_applications', ['id' => $application->id, 'status' => 'hired']);
        $this->assertSame('2026-12-01', $application->fresh()->start_date->toDateString());
        foreach (['approved', 'hired'] as $event) {
            $this->assertDatabaseHas('telegram_deliveries', ['recipient_type' => 'applicant', 'recipient_id' => $applicant->id, 'event' => $event, 'status' => 'sent']);
        }
        Http::assertNotSent(fn (Request $request): bool => ($request['chat_id'] ?? null) === '333'
            || str_contains((string) ($request['text'] ?? ''), 'Private') || str_contains((string) ($request['text'] ?? ''), '35000.50'));
        Http::assertSentCount(5);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('email_deliveries', 0);
        Mail::assertNothingSent();
    }

    public function test_written_exam_waits_for_manual_grading_before_sending_result_notification(): void
    {
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $application = JobApplication::factory()->approved()->create();
        TelegramConnection::factory()->connected('222')->create(['recipient_type' => 'applicant', 'recipient_id' => $application->applicant_id]);
        $exam = RecruitmentExam::factory()->create();
        Mail::fake();
        $this->fakeTelegram();
        $service = app(RecruitmentService::class);

        $attempt = $service->assign($application, $exam, $staff);
        $this->drainQueue();

        $this->assertDatabaseHas('telegram_deliveries', ['resource_type' => 'applicant_exam_attempt', 'resource_id' => $attempt->id, 'event' => 'assigned', 'status' => 'sent']);
        $answers = array_map(static fn (array $question): array => ['question_id' => $question['id'], 'answer' => $question['type'] === 'multiple_choice' ? '0' : 'Private written answer'], $attempt->questions);
        $service->submit($attempt, $application->applicant, $answers);

        $this->assertDatabaseCount('telegram_deliveries', 1);
        $this->assertDatabaseCount('jobs', 0);
        $written = array_values(array_filter($attempt->questions, static fn (array $question): bool => $question['type'] === 'written'));
        $service->grade($attempt, $staff, [['question_id' => $written[0]['id'], 'score' => 8, 'feedback' => 'Private grading feedback']]);
        $this->drainQueue();

        $this->assertDatabaseHas('telegram_deliveries', ['resource_type' => 'applicant_exam_attempt', 'resource_id' => $attempt->id, 'event' => 'result_ready', 'status' => 'sent']);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request['text'], 'Recruitment exam result ready') && str_contains($request['text'], '/applicant/exams/'.$attempt->id));
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) ($request['text'] ?? ''), 'Private'));
        Http::assertSentCount(3);
        $this->assertDatabaseCount('email_deliveries', 0);
        Mail::assertNothingSent();
    }

    public function test_automatically_graded_exam_queues_a_result_update_without_manual_grading(): void
    {
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $application = JobApplication::factory()->approved()->create();
        TelegramConnection::factory()->connected()->create(['recipient_type' => 'applicant', 'recipient_id' => $application->applicant_id]);
        $exam = RecruitmentExam::factory()->create([
            'questions' => [['id' => 'question-one', 'type' => 'multiple_choice', 'prompt' => 'Private question', 'points' => 5, 'options' => ['Private option', 'Other'], 'correct_option' => 0]],
            'max_score' => 5,
        ]);
        Mail::fake();
        $this->fakeTelegram();
        $service = app(RecruitmentService::class);
        $attempt = $service->assign($application, $exam, $staff);
        $this->drainQueue();

        $graded = $service->submit($attempt, $application->applicant, [['question_id' => 'question-one', 'answer' => '0']]);
        $this->drainQueue();

        $this->assertSame('graded', $graded->status);
        $this->assertDatabaseHas('telegram_deliveries', ['resource_id' => $attempt->id, 'event' => 'result_ready', 'status' => 'sent']);
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) ($request['text'] ?? ''), 'Private'));
        Http::assertSentCount(3);
        Mail::assertNothingSent();
    }

    #[TestWith(['cancel', 4])]
    #[TestWith(['reject_application', 5])]
    public function test_interview_schedule_and_cancellation_notify_only_current_panel_and_applicant(string $action, int $expectedDeliveries): void
    {
        $this->freezeTime();
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $application = JobApplication::factory()->approved()->create();
        TelegramConnection::factory()->connected('111')->create(['recipient_id' => $staff->id]);
        TelegramConnection::factory()->connected('222')->create(['recipient_type' => 'applicant', 'recipient_id' => $application->applicant_id]);
        Mail::fake();
        $this->fakeTelegram();
        Event::fake([InterviewEnded::class]);
        $service = app(RecruitmentInterviewService::class);
        $interview = $service->schedule($application, $staff, [
            'round' => 'initial', 'scheduled_at' => now()->addHour()->toIso8601String(), 'duration_minutes' => 30,
            'interviewer_ids' => [$staff->id], 'private_notes' => 'Private interview notes',
        ]);
        $this->drainQueue();

        $this->assertSame(2, TelegramDelivery::query()->where('event', 'scheduled')->where('status', 'sent')->count());
        if ($action === 'cancel') {
            $service->cancel($interview, $staff, 'Private cancellation details');
        } else {
            app(RecruitmentService::class)->reject($application, $staff, 'Private rejection details');
        }
        $this->drainQueue();

        $this->assertSame(2, TelegramDelivery::query()->where('resource_type', 'recruitment_interview')->where('event', 'cancelled')->where('status', 'sent')->count());
        $this->assertDatabaseCount('telegram_deliveries', $expectedDeliveries);
        $this->assertSame('cancelled', $interview->fresh()->status);
        foreach (TelegramDelivery::query()->where('resource_type', 'recruitment_interview')->get() as $delivery) {
            $this->assertSame($interview->scheduled_at->format('Y-m-d H:i:s'), $delivery->source_version);
        }
        Event::assertDispatched(InterviewEnded::class, fn (InterviewEnded $event): bool => $event->interviewUuid === $interview->uuid);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '111' && str_contains($request['text'], '/recruitment/interviews/'.$interview->id));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '222' && str_contains($request['text'], '/applicant/interviews/'.$interview->id));
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) ($request['text'] ?? ''), 'Private'));
        Http::assertSentCount($expectedDeliveries + 1);
        $this->assertDatabaseCount('email_deliveries', 0);
        Mail::assertNothingSent();
    }

    public function test_unlinked_employee_keeps_email_notifications_without_telegram_consent(): void
    {
        config(['hris_mail.enabled' => true]);
        $user = $this->user();
        $this->employee($user);
        Mail::fake();
        Http::preventStrayRequests();

        $this->actingAs($user)->putJson('/api/v1/auth/password', [
            'current_password' => 'password', 'password' => 'changed-password-2026',
            'password_confirmation' => 'changed-password-2026',
        ])->assertOk();
        $this->drainQueue();

        $this->assertDatabaseCount('email_deliveries', 1);
        $this->assertDatabaseCount('telegram_deliveries', 0);
        Mail::assertSent(HrisUpdateMail::class, fn (HrisUpdateMail $mail): bool => $mail->hasTo($user->email));
        Http::assertNothingSent();
    }

    public function test_disabled_channels_queue_nothing_even_for_a_connected_account(): void
    {
        config(['telegram.enabled' => false]);
        $user = $this->user();
        TelegramConnection::factory()->connected()->create(['recipient_id' => $user->id]);
        Queue::fake([DeliverHrisEmail::class, DeliverHrisTelegram::class]);
        Mail::fake();
        Http::preventStrayRequests();

        $service = app(OutboundNotificationService::class);
        $service->queue('user', $user->id, 'password_changed', $user);

        $this->assertFalse($service->enabled());
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertDatabaseCount('telegram_deliveries', 0);
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    private function fakeTelegram(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/bot12345:events_token/getMe' => Http::response(['ok' => true, 'result' => ['id' => 12345, 'is_bot' => true, 'username' => 'example_bot']]),
            'https://api.telegram.org/bot12345:events_token/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }

    private function drainQueue(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'notifications', '--stop-when-empty' => true, '--memory' => 512, '--sleep' => 0])->assertExitCode(0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }
}
