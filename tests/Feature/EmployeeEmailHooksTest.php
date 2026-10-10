<?php

namespace Tests\Feature;

use App\Models\EmailDelivery;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Services\Announcement\AnnouncementService;
use App\Services\Cto\CtoService;
use App\Services\Payslip\PayslipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\FormRequestTestCase;

class EmployeeEmailHooksTest extends FormRequestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hris_mail.enabled' => true,
            'hris_mail.frontend_url' => 'https://hris.example.test',
            'queue.default' => 'database',
        ]);
    }

    public function test_cto_submission_and_rejection_email_the_existing_recipients_after_commit(): void
    {
        $reviewer = $this->user('cto.view', 'cto.approve');
        $supervisor = $this->employee($reviewer);
        $owner = $this->user('cto.view');
        $employee = $this->employee($owner, ['supervisor_employee_id' => $supervisor->id]);
        $service = app(CtoService::class);

        $record = $service->submit($employee, $owner, '2026-10-08 08:00:00', '2026-10-08 10:00:00', 'Private reason');
        $this->assertCount(0, $this->messages());
        $this->drainQueue();

        $this->assertCount(1, $this->messages());
        $this->assertSame($reviewer->email, $this->messages()[0]->getTo()[0]->getAddress());
        $this->assertStringContainsString('https://hris.example.test/requests/cto?record='.$record->id, $this->messages()[0]->getHtmlBody());
        $this->assertSame(1, $reviewer->notifications()->count());

        $service->reject($record, $reviewer, 'Private remarks');
        $this->drainQueue();

        $this->assertCount(2, $this->messages());
        $this->assertSame($owner->email, $this->messages()[1]->getTo()[0]->getAddress());
        $this->assertStringContainsString('https://hris.example.test/my/requests/cto?record='.$record->id, $this->messages()[1]->getHtmlBody());
        $this->assertSame(2, EmailDelivery::query()->where('status', 'sent')->count());
        $this->assertSame(1, $owner->notifications()->count());
        foreach ($this->messages() as $message) {
            $this->assertStringNotContainsString('Private', $message->getHtmlBody().$message->getTextBody());
        }

        try {
            $service->reject($record, $reviewer);
            $this->fail('A repeated rejection must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('pending', $exception->getMessage());
        }
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('email_deliveries', 2);
    }

    public function test_rolled_back_cto_submission_produces_no_in_app_or_email_delivery(): void
    {
        $reviewer = $this->user('cto.view', 'cto.approve');
        $owner = $this->user('cto.view');
        $employee = $this->employee($owner, ['supervisor_employee_id' => $this->employee($reviewer)->id]);
        DB::beginTransaction();
        app(CtoService::class)->submit($employee, $owner, '2026-10-08 08:00:00', '2026-10-08 10:00:00', 'Private reason');
        $this->assertDatabaseCount('jobs', 0);

        DB::rollBack();
        $this->drainQueue();

        $this->assertDatabaseCount('cto_requests', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertCount(0, $this->messages());
    }

    public function test_announcement_publication_emails_readers_without_private_content(): void
    {
        $reader = $this->user('announcements.view');
        $author = $this->user();
        $service = app(AnnouncementService::class);
        $announcement = $service->createDraft($author, 'Private title', 'Private announcement content');
        $this->assertDatabaseCount('jobs', 0);

        $service->publish($announcement);
        $this->drainQueue();

        $this->assertCount(1, $this->messages());
        $message = $this->messages()[0];
        $this->assertSame($reader->email, $message->getTo()[0]->getAddress());
        $this->assertStringContainsString('/manage/announcements?record='.$announcement->id, $message->getHtmlBody());
        $this->assertStringNotContainsString('Private', $message->getHtmlBody().$message->getTextBody());
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_payslip_publication_emails_only_the_employee_without_pay_or_document_details(): void
    {
        $owner = $this->user('payslips.view');
        $employee = $this->employee($owner);
        $finance = $this->user('payslips.view', 'employees.view');
        $period = PayrollPeriod::query()->create(['code' => 'EMAIL', 'period_start' => '2026-10-01', 'period_end' => '2026-10-15', 'pay_date' => '2026-10-20', 'pay_frequency' => 'semi_monthly']);
        $run = PayrollRun::query()->create(['payroll_period_id' => $period->id, 'run_number' => 1, 'status' => 'finalized']);
        $entry = PayrollEntry::query()->create(['payroll_run_id' => $run->id, 'employee_id' => $employee->id, 'basic_salary' => '12345.67', 'pay_frequency' => 'semi_monthly', 'net_pay' => '12345.67']);
        $service = app(PayslipService::class);
        $payslip = $service->registerGenerated($entry, $finance, 'private/payroll/secret.pdf');
        $this->assertDatabaseCount('jobs', 0);

        $service->publish($payslip, $finance);
        $this->drainQueue();

        $this->assertCount(1, $this->messages());
        $message = $this->messages()[0];
        $this->assertSame($owner->email, $message->getTo()[0]->getAddress());
        $this->assertStringContainsString('/my/payslips?record='.$payslip->id, $message->getHtmlBody());
        $this->assertStringNotContainsString('12345.67', $message->getHtmlBody().$message->getTextBody());
        $this->assertStringNotContainsString('secret.pdf', $message->getHtmlBody().$message->getTextBody());
    }

    public function test_password_change_queues_a_security_email_without_serializing_credentials(): void
    {
        $user = $this->user();
        $this->employee($user);
        $this->actingAs($user)->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'changed-password-2026',
            'password_confirmation' => 'changed-password-2026',
        ])->assertOk();

        $this->assertTrue(Hash::check('changed-password-2026', $user->fresh()->password));
        $this->assertDatabaseHas('email_deliveries', ['recipient_type' => 'user', 'recipient_id' => $user->id, 'event' => 'password_changed', 'status' => 'queued']);
        $payload = DB::table('jobs')->value('payload');
        $this->assertStringNotContainsString('changed-password-2026', $payload);
        $this->assertStringNotContainsString($user->fresh()->password, $payload);
        $this->assertCount(0, $this->messages());
        $this->drainQueue();

        $this->assertCount(1, $this->messages());
        $message = $this->messages()[0];
        $this->assertSame($user->email, $message->getTo()[0]->getAddress());
        $this->assertStringContainsString('https://hris.example.test/profile', $message->getHtmlBody());
        $this->assertStringNotContainsString('changed-password-2026', $message->getHtmlBody().$message->getTextBody());
    }

    public function test_wrong_current_password_returns_422_without_queuing_a_security_email(): void
    {
        $user = $this->user();
        $this->employee($user);

        $this->actingAs($user)->putJson('/api/v1/auth/password', [
            'current_password' => 'incorrect-password',
            'password' => 'changed-password-2026',
            'password_confirmation' => 'changed-password-2026',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_rolled_back_password_change_leaves_no_email_job_or_changed_credentials(): void
    {
        $user = $this->user();
        $this->employee($user);
        DB::beginTransaction();
        $this->actingAs($user)->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'changed-password-2026',
            'password_confirmation' => 'changed-password-2026',
        ])->assertOk();
        $this->assertDatabaseCount('jobs', 0);

        DB::rollBack();

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertCount(0, $this->messages());
    }

    private function drainQueue(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'notifications', '--stop-when-empty' => true, '--sleep' => 0])->assertExitCode(0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    /** @return list<Email> */
    private function messages(): array
    {
        return Mail::mailer()->getSymfonyTransport()->messages()->map(fn ($message): Email => $message->getOriginalMessage())->all();
    }
}
