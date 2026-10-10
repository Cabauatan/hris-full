<?php

namespace Tests\Feature;

use App\Jobs\DeliverHrisEmail;
use App\Mail\HrisUpdateMail;
use App\Models\Announcement;
use App\Models\Applicant;
use App\Models\ApplicantExamAttempt;
use App\Models\CtoRequest;
use App\Models\EmailDelivery;
use App\Models\JobApplication;
use App\Models\RecruitmentInterview;
use App\Models\User;
use App\Services\Notification\EmailNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\FormRequestTestCase;

class EmailDeliveryTest extends FormRequestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['hris_mail.enabled' => true, 'hris_mail.frontend_url' => 'http://127.0.0.1:5002', 'queue.default' => 'database', 'mail.default' => 'array']);
    }

    public function test_disabled_email_does_not_create_a_delivery_or_job(): void
    {
        Mail::fake();
        $user = $this->user();
        config(['hris_mail.enabled' => false]);
        app(EmailNotificationService::class)->queue('user', $user->id, 'password_changed', $user);

        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertDatabaseCount('jobs', 0);

        config(['hris_mail.enabled' => true]);
        app(EmailNotificationService::class)->queue('user', $user->id, 'password_changed', $user);
        $delivery = EmailDelivery::query()->firstOrFail();
        config(['hris_mail.enabled' => false]);

        app(EmailNotificationService::class)->deliver($delivery->id);

        Mail::assertNothingSent();
        $this->assertSame('skipped', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->sent_at);
    }

    public function test_event_identity_deduplicates_without_colliding_across_recipient_kinds(): void
    {
        $user = $this->user('recruitment.view', 'recruitment.manage');
        $applicant = Applicant::factory()->create(['id' => $user->id]);
        $application = JobApplication::factory()->create(['applicant_id' => $applicant->id]);
        $emails = app(EmailNotificationService::class);

        $emails->queue('job_application', $application->id, 'submitted', $user, 'same-event');
        $emails->queue('job_application', $application->id, 'submitted', $user, 'same-event');
        $emails->queue('job_application', $application->id, 'submitted', $applicant, 'same-event');

        $this->assertDatabaseCount('email_deliveries', 2);
        $this->assertDatabaseCount('jobs', 2);
        $this->assertCount(2, EmailDelivery::query()->pluck('id')->unique());
        $payload = DB::table('jobs')->first()->payload;
        $command = unserialize(json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['data']['command']);
        $this->assertInstanceOf(DeliverHrisEmail::class, $command);
        $this->assertTrue(Str::isUuid($command->deliveryId));
        $this->assertStringNotContainsString($user->email, $payload);
        $this->assertStringNotContainsString($applicant->email, $payload);
        $this->assertStringNotContainsString('password_changed', serialize($command));
    }

    public function test_rollback_discards_delivery_and_after_commit_job(): void
    {
        $user = $this->user();
        DB::beginTransaction();
        app(EmailNotificationService::class)->queue('user', $user->id, 'password_changed', $user);
        $this->assertDatabaseCount('email_deliveries', 1);
        $this->assertDatabaseCount('jobs', 0);

        DB::rollBack();

        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_committed_job_sends_through_array_mailer_and_deduplicates_retry(): void
    {
        $user = $this->user();
        DB::transaction(fn () => app(EmailNotificationService::class)->queue('user', $user->id, 'password_changed', $user, 'security-event'));
        $delivery = EmailDelivery::query()->firstOrFail();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame('notifications', DB::table('jobs')->value('queue'));

        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'notifications', '--once' => true, '--sleep' => 0])->assertExitCode(0);
        (new DeliverHrisEmail($delivery->id))->handle(app(EmailNotificationService::class));

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->sent_at);
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertSame('[HRIS] Password changed', $messages->first()->getOriginalMessage()->getSubject());
    }

    public function test_mail_veto_does_not_mark_delivery_as_sent(): void
    {
        Mail::fake();
        $user = $this->user();
        app(EmailNotificationService::class)->queue('user', $user->id, 'password_changed', $user);
        $delivery = EmailDelivery::query()->firstOrFail();

        app(EmailNotificationService::class)->deliver($delivery->id);

        Mail::assertSent(HrisUpdateMail::class, fn (HrisUpdateMail $mail): bool => $mail->hasTo($user->email) && $mail->actionUrl === 'http://127.0.0.1:5002/profile');
        $this->assertSame('skipped', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->sent_at);
    }

    #[DataProvider('unsupportedTransitions')]
    public function test_unsupported_resource_events_fail_without_queueing(string $type, string $event): void
    {
        $user = $this->user();
        try {
            app(EmailNotificationService::class)->queue($type, $user->id, $event, $user);
            $this->fail('Unsupported email transitions must fail.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('email_deliveries', 0);
            $this->assertDatabaseCount('jobs', 0);
        }
    }

    public static function unsupportedTransitions(): array
    {
        return [['unknown', 'registered'], ['applicant', 'password_changed'], ['payslip', 'approved']];
    }

    #[DataProvider('unavailableRecipients')]
    public function test_delivery_reload_skips_unavailable_recipients(string $change): void
    {
        Mail::fake();
        $user = $this->user();
        app(EmailNotificationService::class)->queue('user', $user->id, 'password_changed', $user);
        $delivery = EmailDelivery::query()->firstOrFail();
        match ($change) {
            'deleted' => $user->delete(),
            'inactive' => $user->update(['is_active' => false]),
            'invalid_email' => $user->update(['email' => 'invalid-email']),
        };

        app(EmailNotificationService::class)->deliver($delivery->id);

        Mail::assertNothingSent();
        $this->assertSame('skipped', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->sent_at);
    }

    public static function unavailableRecipients(): array
    {
        return [['deleted'], ['inactive'], ['invalid_email']];
    }

    #[DataProvider('lostReviewerAccess')]
    public function test_pending_request_checks_fresh_authority_permissions_and_state(string $change): void
    {
        Mail::fake();
        [$owner, $reviewer, $record] = $this->pendingCto();
        app(EmailNotificationService::class)->queue('cto', $record->id, 'pending', $reviewer);
        $delivery = EmailDelivery::query()->firstOrFail();
        if ($change === 'permission') {
            $reviewer->permissions()->detach();
        } elseif ($change === 'authority') {
            $record->employee->update(['supervisor_employee_id' => null]);
        } else {
            $record->update(['status' => 'approved']);
        }

        app(EmailNotificationService::class)->deliver($delivery->id);

        Mail::assertNothingSent();
        $this->assertSame('skipped', $delivery->fresh()->status);
    }

    public static function lostReviewerAccess(): array
    {
        return [['permission'], ['authority'], ['state']];
    }

    public function test_pending_request_mail_uses_reviewer_link_and_current_address(): void
    {
        Mail::fake();
        [$owner, $reviewer, $record] = $this->pendingCto();
        app(EmailNotificationService::class)->queue('cto', $record->id, 'pending', $reviewer);
        $reviewer->update(['email' => 'current-reviewer@example.test']);

        app(EmailNotificationService::class)->deliver(EmailDelivery::query()->firstOrFail()->id);

        Mail::assertSent(HrisUpdateMail::class, fn (HrisUpdateMail $mail): bool => $mail->hasTo('current-reviewer@example.test')
            && $mail->actionUrl === 'http://127.0.0.1:5002/requests/cto?record='.$record->id);
        $this->assertSame($owner->id, $record->employee->user_id);
    }

    public function test_owner_outcome_mail_uses_own_route_without_private_reason(): void
    {
        Mail::fake();
        [$owner, $reviewer, $record] = $this->pendingCto();
        $record->update(['status' => 'rejected', 'review_remarks' => 'Private reviewer remarks']);
        app(EmailNotificationService::class)->queue('cto', $record->id, 'rejected', $owner);

        app(EmailNotificationService::class)->deliver(EmailDelivery::query()->firstOrFail()->id);

        Mail::assertSent(HrisUpdateMail::class, function (HrisUpdateMail $mail) use ($record): bool {
            $this->assertStringNotContainsString('Private', $mail->render());
            $this->assertSame('http://127.0.0.1:5002/my/requests/cto?record='.$record->id, $mail->actionUrl);

            return true;
        });
    }

    public function test_rescheduled_publication_is_skipped_even_if_currently_visible(): void
    {
        Mail::fake();
        $this->freezeTime();
        $reader = $this->user('announcements.view');
        $announcement = Announcement::query()->create(['title' => 'Private title', 'content' => 'Private announcement content', 'priority' => 'normal', 'is_active' => true, 'published_at' => now()->subHour()]);
        app(EmailNotificationService::class)->queue('announcement', $announcement->id, 'published', $reader, sourceVersion: $announcement->published_at->format('Y-m-d H:i:s'));
        $announcement->update(['published_at' => now()]);

        app(EmailNotificationService::class)->deliver(EmailDelivery::query()->firstOrFail()->id);

        Mail::assertNothingSent();
        $this->assertSame('skipped', EmailDelivery::query()->firstOrFail()->status);
    }

    public function test_applicant_cannot_receive_another_applicants_application_mail(): void
    {
        Mail::fake();
        $application = JobApplication::factory()->approved()->create();
        $other = Applicant::factory()->create();
        app(EmailNotificationService::class)->queue('job_application', $application->id, 'approved', $other);

        app(EmailNotificationService::class)->deliver(EmailDelivery::query()->firstOrFail()->id);

        Mail::assertNothingSent();
        $this->assertSame('skipped', EmailDelivery::query()->firstOrFail()->status);
    }

    public function test_exam_results_require_completed_grade_and_never_expose_answers_or_scores(): void
    {
        Mail::fake();
        $attempt = ApplicantExamAttempt::factory()->create();
        $applicant = $attempt->application->applicant;
        $emails = app(EmailNotificationService::class);
        $emails->queue('applicant_exam_attempt', $attempt->id, 'result_ready', $applicant, 'partial');
        $emails->deliver(EmailDelivery::query()->where('event_id', 'partial')->firstOrFail()->id);
        Mail::assertNothingSent();
        $attempt->update(['status' => 'graded', 'score' => '9876.00', 'percentage' => '80.00', 'passed' => true]);
        $emails->queue('applicant_exam_attempt', $attempt->id, 'result_ready', $applicant, 'incomplete');
        $emails->deliver(EmailDelivery::query()->where('event_id', 'incomplete')->firstOrFail()->id);
        Mail::assertNothingSent();
        $attempt->update(['graded_at' => now(), 'answers' => ['secret-answer']]);
        $attempt->application->update(['status' => 'hired']);
        $emails->queue('applicant_exam_attempt', $attempt->id, 'result_ready', $applicant, 'complete');
        $emails->deliver(EmailDelivery::query()->where('event_id', 'complete')->firstOrFail()->id);

        Mail::assertSent(HrisUpdateMail::class, function (HrisUpdateMail $mail) use ($attempt): bool {
            $html = $mail->render();
            $this->assertStringNotContainsString('secret-answer', $html);
            $this->assertStringNotContainsString('9876', $html);
            $this->assertStringNotContainsString('80.00', $html);

            return $mail->actionUrl === 'http://127.0.0.1:5002/applicant/exams/'.$attempt->id;
        });
        Mail::assertSentCount(1);
    }

    public function test_removed_interview_panel_member_does_not_receive_an_invitation(): void
    {
        Mail::fake();
        $panel = $this->user('recruitment.manage', 'recruitment.view');
        $interview = RecruitmentInterview::factory()->create();
        $interview->interviewers()->attach($panel);
        app(EmailNotificationService::class)->queue('recruitment_interview', $interview->id, 'scheduled', $panel);
        $interview->interviewers()->detach($panel);

        app(EmailNotificationService::class)->deliver(EmailDelivery::query()->firstOrFail()->id);

        Mail::assertNothingSent();
        $this->assertSame('skipped', EmailDelivery::query()->firstOrFail()->status);
    }

    public function test_expired_interview_invitation_is_skipped(): void
    {
        Mail::fake();
        $this->freezeTime();
        $interview = RecruitmentInterview::factory()->create(['scheduled_at' => now()->subHours(2), 'duration_minutes' => 30]);
        app(EmailNotificationService::class)->queue('recruitment_interview', $interview->id, 'scheduled', $interview->application->applicant);

        app(EmailNotificationService::class)->deliver(EmailDelivery::query()->firstOrFail()->id);

        Mail::assertNothingSent();
        $this->assertSame('skipped', EmailDelivery::query()->firstOrFail()->status);
    }

    public function test_terminal_failure_keeps_only_metadata_and_failed_delivery_can_be_retried(): void
    {
        $user = $this->user();
        app(EmailNotificationService::class)->queue('user', $user->id, 'password_changed', $user);
        $delivery = EmailDelivery::query()->firstOrFail();
        $manager = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Private SMTP diagnostic'));
        $job = new DeliverHrisEmail($delivery->id);
        try {
            $job->handle(app(EmailNotificationService::class));
            $this->fail('A mail failure must be retried.');
        } catch (RuntimeException) {
            $this->assertSame('queued', $delivery->fresh()->status);
        }
        $job->failed(new RuntimeException('Private SMTP diagnostic'));
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertStringNotContainsString('Private SMTP', $delivery->fresh()->toJson());
        Mail::swap($manager);

        $job->handle(app(EmailNotificationService::class));

        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_mail_render_is_hris_branded_escaped_and_has_no_attachments(): void
    {
        $mail = new HrisUpdateMail('HRIS update', '<script>private</script>', 'http://127.0.0.1:5002/profile', 'http://127.0.0.1:5002');

        $mail->assertSeeInHtml('HRIS');
        $mail->assertSeeInText('HRIS');
        $mail->assertSeeInHtml('&lt;script&gt;private&lt;/script&gt;', false);
        $mail->assertDontSeeInHtml('<script>private</script>', false);
        $mail->assertSeeInHtml('http://127.0.0.1:5002/profile');
        $this->assertEmpty($mail->attachments);
    }

    /** @return array{User, User, CtoRequest} */
    private function pendingCto(): array
    {
        $reviewer = $this->user('cto.view', 'cto.approve');
        $supervisor = $this->employee($reviewer);
        $owner = $this->user('cto.view');
        $employee = $this->employee($owner, ['supervisor_employee_id' => $supervisor->id]);
        $record = CtoRequest::query()->create([
            'employee_id' => $employee->id, 'created_by_user_id' => $owner->id,
            'start_at' => '2026-10-05 08:00:00', 'end_at' => '2026-10-05 10:00:00',
            'requested_minutes' => 120, 'status' => 'pending', 'reason' => 'Private reason',
        ]);

        return [$owner, $reviewer, $record];
    }

    public function test_delivery_factory_preserves_uuid_and_typed_metadata(): void
    {
        $delivery = EmailDelivery::factory()->create(['sent_at' => now()]);

        $this->assertTrue(Str::isUuid($delivery->id));
        $this->assertIsInt($delivery->resource_id);
        $this->assertSame($delivery->recipient_id, $delivery->resource_id);
        $this->assertInstanceOf(Carbon::class, $delivery->occurred_at);
        $this->assertInstanceOf(Carbon::class, $delivery->sent_at);
        $this->assertNull($delivery->source_version);
    }

    public function test_ledger_schema_persists_only_safe_delivery_metadata(): void
    {
        $this->user();
        $id = (string) Str::uuid();
        DB::table('email_deliveries')->insert([
            'id' => $id, 'resource_type' => 'user', 'resource_id' => 1,
            'event' => 'password_changed', 'event_id' => (string) Str::uuid(),
            'recipient_type' => 'user', 'recipient_id' => 1, 'occurred_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = (array) DB::table('email_deliveries')->where('id', $id)->first();
        $this->assertSame('queued', $row['status']);
        $this->assertNull($row['sent_at']);
        $this->assertSame([
            'id', 'resource_type', 'resource_id', 'event', 'event_id', 'recipient_type',
            'recipient_id', 'source_version', 'occurred_at', 'status', 'sent_at', 'created_at', 'updated_at',
        ], array_keys($row));
    }
}
