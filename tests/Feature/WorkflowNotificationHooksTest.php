<?php

namespace Tests\Feature;

use App\Jobs\DeliverHrisNotification;
use App\Models\AttendanceCorrectionRequest;
use App\Models\CtoBalance;
use App\Models\CtoRequest;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\EmployeeProfileChangeRequest;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OfficialBusinessRequest;
use App\Models\OvertimeRequest;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\TravelOrderRequest;
use App\Models\User;
use App\Services\Announcement\AnnouncementService;
use App\Services\AttendanceCorrection\AttendanceCorrectionService;
use App\Services\Cto\CtoService;
use App\Services\EmployeeProfile\EmployeeProfileChangeService;
use App\Services\Leave\LeaveService;
use App\Services\OfficialBusiness\OfficialBusinessService;
use App\Services\Overtime\OvertimeService;
use App\Services\Payslip\PayslipService;
use App\Services\TravelOrder\TravelOrderService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\FormRequestTestCase;

class WorkflowNotificationHooksTest extends FormRequestTestCase
{
    #[DataProvider('workflows')]
    public function test_successful_submission_queues_one_pending_update_for_the_persisted_request(string $type): void
    {
        $this->freezeTime();
        $owner = $this->user();
        $employee = $this->employee($owner);
        Queue::fake([DeliverHrisNotification::class]);

        $record = $this->submitWorkflow($type, $employee, $owner);

        $this->assertSame('pending', $record->fresh()->status);
        $this->assertDatabaseHas($record->getTable(), ['id' => $record->id, 'employee_id' => $employee->id, 'created_by_user_id' => $owner->id]);
        $this->assertQueuedTransition($type, $record, 'pending');
    }

    #[DataProvider('terminalTransitions')]
    public function test_successful_review_or_cancellation_queues_one_update_with_the_final_state(string $type, string $transition): void
    {
        $this->freezeTime();
        $owner = $this->user();
        $employee = $this->employee($owner);
        $reviewer = $this->user();
        Queue::fake([DeliverHrisNotification::class]);
        $record = $this->submitWorkflow($type, $employee, $owner);
        Queue::fake([DeliverHrisNotification::class]);

        $result = $this->transitionWorkflow($type, $record, $reviewer, $transition);

        $this->assertSame($transition, $result->status);
        $this->assertSame($transition, $record->fresh()->status);
        $this->assertQueuedTransition($type, $record, $transition);
    }

    #[DataProvider('workflows')]
    public function test_failed_submission_creates_neither_request_nor_notification_job(string $type): void
    {
        $owner = $this->user();
        $employee = $this->employee($owner);
        Queue::fake([DeliverHrisNotification::class]);

        try {
            $this->submitWorkflow($type, $employee, $owner, invalid: true);
            $this->fail('Expected the invalid submission to fail.');
        } catch (RuntimeException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        $this->assertSame(0, self::workflowModel($type)::query()->count());
        Queue::assertNotPushed(DeliverHrisNotification::class);
    }

    #[DataProvider('workflows')]
    public function test_repeated_review_leaves_terminal_request_unchanged_and_queues_no_update(string $type): void
    {
        $owner = $this->user();
        $employee = $this->employee($owner);
        $reviewer = $this->user();
        Queue::fake([DeliverHrisNotification::class]);
        $record = $this->submitWorkflow($type, $employee, $owner);
        $this->transitionWorkflow($type, $record, $reviewer, 'rejected');
        Queue::fake([DeliverHrisNotification::class]);

        try {
            $this->transitionWorkflow($type, $record, $reviewer, 'approved');
            $this->fail('Expected the rejected request to prohibit approval.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('pending', $exception->getMessage());
        }

        $this->assertSame('rejected', $record->fresh()->status);
        Queue::assertNotPushed(DeliverHrisNotification::class);
    }

    public function test_profile_application_queues_update_after_the_employee_changes_are_saved(): void
    {
        $this->freezeTime();
        $owner = $this->user();
        $employee = $this->employee($owner);
        $reviewer = $this->user();
        $service = app(EmployeeProfileChangeService::class);
        Queue::fake([DeliverHrisNotification::class]);
        $record = $service->submit($employee, $owner, ['first_name' => 'Updated']);
        $service->approve($record, $reviewer);
        Queue::fake([DeliverHrisNotification::class]);

        $result = $service->apply($record, $reviewer);

        $this->assertSame('applied', $result->status);
        $this->assertSame('Updated', $employee->fresh()->first_name);
        $this->assertQueuedTransition('profile_changes', $record, 'applied');
    }

    public function test_stale_profile_application_queues_no_update_and_keeps_approved_state(): void
    {
        $owner = $this->user();
        $employee = $this->employee($owner);
        $reviewer = $this->user();
        $service = app(EmployeeProfileChangeService::class);
        Queue::fake([DeliverHrisNotification::class]);
        $record = $service->submit($employee, $owner, ['first_name' => 'Updated']);
        $service->approve($record, $reviewer);
        $employee->update(['first_name' => 'Another value']);
        Queue::fake([DeliverHrisNotification::class]);

        try {
            $service->apply($record, $reviewer);
            $this->fail('Expected the stale profile change to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('changed after request submission', $exception->getMessage());
        }

        $this->assertSame('approved', $record->fresh()->status);
        $this->assertSame('Another value', $employee->fresh()->first_name);
        Queue::assertNotPushed(DeliverHrisNotification::class);
    }

    public function test_announcement_publication_queues_one_current_publication_update(): void
    {
        $this->freezeTime();
        $service = app(AnnouncementService::class);
        $draft = $service->createDraft($this->user(), 'Office notice', 'A published notice.');
        Queue::fake([DeliverHrisNotification::class]);

        $record = $service->publish($draft);

        $this->assertSame(now()->format('Y-m-d H:i:s'), $record->published_at->format('Y-m-d H:i:s'));
        $this->assertQueuedTransition('announcement', $record, 'published');
        $job = Queue::pushed(DeliverHrisNotification::class)->sole();
        $this->assertSame($record->published_at->format('Y-m-d H:i:s'), $job->publicationVersion);
        $this->assertNull($job->delay);
    }

    public function test_scheduled_announcement_queues_delivery_at_its_publication_time(): void
    {
        $this->freezeTime();
        $service = app(AnnouncementService::class);
        $draft = $service->createDraft($this->user(), 'Future notice', 'A scheduled notice.');
        $scheduledAt = now()->addDay();
        Queue::fake([DeliverHrisNotification::class]);

        $record = $service->schedule($draft, $scheduledAt);

        $this->assertQueuedTransition('announcement', $record, 'published', $scheduledAt->toIso8601String());
        $job = Queue::pushed(DeliverHrisNotification::class)->sole();
        $this->assertSame($scheduledAt->format('Y-m-d H:i:s'), $job->publicationVersion);
        $this->assertSame($scheduledAt->format('Y-m-d H:i:s'), $job->delay->format('Y-m-d H:i:s'));
    }

    public function test_rescheduled_announcement_queues_delivery_for_the_new_publication_version(): void
    {
        $this->freezeTime();
        $service = app(AnnouncementService::class);
        Queue::fake([DeliverHrisNotification::class]);
        $draft = $service->createDraft($this->user(), 'Future notice', 'A scheduled notice.');
        $scheduled = $service->schedule($draft, now()->addDay());
        $newDate = now()->addDays(2);
        Queue::fake([DeliverHrisNotification::class]);

        $record = $service->reschedule($scheduled, $newDate);

        $this->assertQueuedTransition('announcement', $record, 'published', $newDate->toIso8601String());
        $job = Queue::pushed(DeliverHrisNotification::class)->sole();
        $this->assertSame($newDate->format('Y-m-d H:i:s'), $job->publicationVersion);
        $this->assertSame($newDate->format('Y-m-d H:i:s'), $job->delay->format('Y-m-d H:i:s'));
    }

    public function test_announcement_draft_edit_cancellation_and_activation_do_not_queue_publication(): void
    {
        $this->freezeTime();
        $service = app(AnnouncementService::class);
        Queue::fake([DeliverHrisNotification::class]);
        $scheduled = $service->schedule($service->createDraft($this->user(), 'Notice', 'Initial content.'), now()->addDay());
        Queue::fake([DeliverHrisNotification::class]);

        $draft = $service->createDraft($this->user(), 'Unpublished notice', 'Draft content.');
        $service->update($draft, 'Revised notice', 'Edited content.', 'normal', true);
        $service->cancelSchedule($scheduled);
        $service->deactivate($draft);
        $service->reactivate($draft);

        $this->assertNull($scheduled->fresh()->published_at);
        $this->assertNull($draft->fresh()->published_at);
        Queue::assertNotPushed(DeliverHrisNotification::class);
    }

    public function test_failed_announcement_publication_queues_no_job(): void
    {
        $this->freezeTime();
        $service = app(AnnouncementService::class);
        $draft = $service->createDraft($this->user(), 'Notice', 'Content.');
        Queue::fake([DeliverHrisNotification::class]);

        try {
            $service->publish($draft, now()->subMinute());
            $this->fail('Expected the invalid publication window to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('expiration must be after publication', $exception->getMessage());
        }

        $this->assertNull($draft->fresh()->published_at);
        Queue::assertNotPushed(DeliverHrisNotification::class);
    }

    public function test_payslip_publication_queues_one_update_after_publication_is_saved(): void
    {
        $this->freezeTime();
        $actor = $this->user();
        $service = app(PayslipService::class);
        $payslip = $service->registerGenerated($this->payrollEntry(), $actor, 'payslips/test.html');
        Queue::fake([DeliverHrisNotification::class]);

        $record = $service->publish($payslip, $actor);

        $this->assertSame($actor->id, $record->published_by_user_id);
        $this->assertSame(now()->format('Y-m-d H:i:s'), $record->published_at->format('Y-m-d H:i:s'));
        $this->assertQueuedTransition('payslip', $record, 'published');
    }

    public function test_payslip_registration_and_viewing_do_not_queue_publication_updates(): void
    {
        $actor = $this->user();
        $service = app(PayslipService::class);
        Queue::fake([DeliverHrisNotification::class]);
        $published = $service->publish($service->registerGenerated($this->payrollEntry(), $actor, 'payslips/published.html'), $actor);
        Queue::fake([DeliverHrisNotification::class]);

        $draft = $service->registerGenerated($this->payrollEntry(), $actor, 'payslips/draft.html');
        $viewed = $service->markViewed($published);

        $this->assertNull($draft->published_at);
        $this->assertNotNull($viewed->viewed_at);
        Queue::assertNotPushed(DeliverHrisNotification::class);
    }

    public function test_repeated_payslip_publication_queues_no_duplicate_update(): void
    {
        $actor = $this->user();
        $service = app(PayslipService::class);
        Queue::fake([DeliverHrisNotification::class]);
        $published = $service->publish($service->registerGenerated($this->payrollEntry(), $actor, 'payslips/test.html'), $actor);
        Queue::fake([DeliverHrisNotification::class]);

        try {
            $service->publish($published, $actor);
            $this->fail('Expected the published payslip to prohibit publication again.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Payslip is already published.', $exception->getMessage());
        }

        $this->assertNotNull($published->fresh()->published_at);
        Queue::assertNotPushed(DeliverHrisNotification::class);
    }

    /** @return array<string, array{string}> */
    public static function workflows(): array
    {
        return [
            'leave' => ['leave'], 'cto' => ['cto'], 'overtime' => ['overtime'],
            'official_business' => ['official_business'], 'travel_order' => ['travel_order'],
            'attendance_correction' => ['attendance_correction'], 'profile_changes' => ['profile_changes'],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function terminalTransitions(): array
    {
        $cases = [];
        foreach (self::workflows() as [$type]) {
            foreach (['approved', 'rejected', 'cancelled'] as $transition) {
                $cases[$type.' '.$transition] = [$type, $transition];
            }
        }

        return $cases;
    }

    /** @return class-string<Model> */
    private static function workflowModel(string $type): string
    {
        return match ($type) {
            'leave' => LeaveRequest::class,
            'cto' => CtoRequest::class,
            'overtime' => OvertimeRequest::class,
            'official_business' => OfficialBusinessRequest::class,
            'travel_order' => TravelOrderRequest::class,
            'attendance_correction' => AttendanceCorrectionRequest::class,
            'profile_changes' => EmployeeProfileChangeRequest::class,
        };
    }

    private function workflowService(string $type): object
    {
        return app(match ($type) {
            'leave' => LeaveService::class,
            'cto' => CtoService::class,
            'overtime' => OvertimeService::class,
            'official_business' => OfficialBusinessService::class,
            'travel_order' => TravelOrderService::class,
            'attendance_correction' => AttendanceCorrectionService::class,
            'profile_changes' => EmployeeProfileChangeService::class,
        });
    }

    private function submitWorkflow(string $type, Employee $employee, User $owner, bool $invalid = false): Model
    {
        $reason = $invalid ? '' : 'A private reason.';
        $service = $this->workflowService($type);
        if ($type === 'leave') {
            $leaveType = LeaveType::query()->create(['code' => (string) Str::uuid(), 'name' => 'Unpaid leave', 'requires_balance' => false, 'is_active' => true]);

            return $service->submit($employee, $leaveType, $owner, '2026-10-06', '2026-10-06', 'full_day', '1.00', $reason);
        }
        if ($type === 'attendance_correction') {
            $attendance = DailyAttendance::query()->create(['employee_id' => $employee->id, 'attendance_date' => '2026-10-06', 'actual_in' => '2026-10-06 08:00:00', 'actual_out' => '2026-10-06 17:00:00', 'status' => 'present']);

            return $service->submit($employee, $owner, '2026-10-06', '2026-10-06 09:00:00', '2026-10-06 17:00:00', $reason, $attendance->id);
        }

        return match ($type) {
            'profile_changes' => $service->submit($employee, $owner, $invalid ? [] : ['first_name' => 'Updated']),
            'cto', 'overtime' => $service->submit($employee, $owner, '2026-10-06 08:00:00', '2026-10-06 09:00:00', $reason),
            'official_business' => $service->submit($employee, $owner, '2026-10-06 08:00:00', '2026-10-06 09:00:00', $reason, 'Office'),
            'travel_order' => $service->submit($employee, $owner, '2026-10-06 08:00:00', '2026-10-06 09:00:00', 'Office', $reason),
        };
    }

    private function transitionWorkflow(string $type, Model $record, User $actor, string $transition): Model
    {
        $service = $this->workflowService($type);
        if ($transition === 'cancelled') {
            return $type === 'profile_changes' ? $service->cancel($record, 'Plans changed.') : $service->cancel($record, $actor, 'Plans changed.');
        }
        if ($transition === 'rejected') {
            return $service->reject($record, $actor, 'Revise the request.');
        }
        if ($type === 'cto') {
            CtoBalance::query()->firstOrCreate(['employee_id' => $record->employee_id], ['balance_minutes' => 120]);
        }

        return match ($type) {
            'cto', 'overtime' => $service->approve($record, $actor, 30),
            'leave' => $service->approve($record, $actor, '0.50'),
            default => $service->approve($record, $actor),
        };
    }

    private function assertQueuedTransition(string $type, Model $record, string $event, ?string $occurredAt = null): void
    {
        Queue::assertPushed(DeliverHrisNotification::class, 1);
        $job = Queue::pushed(DeliverHrisNotification::class)->sole();
        $this->assertSame($type, $job->resourceType);
        $this->assertSame($record->id, $job->resourceId);
        $this->assertSame($event, $job->event);
        $this->assertSame('notifications', $job->queue);
        $this->assertTrue($job->afterCommit);
        $this->assertSame($occurredAt ?? now()->toIso8601String(), $job->occurredAt);
    }

    private function payrollEntry(): PayrollEntry
    {
        $period = PayrollPeriod::query()->create(['code' => 'NOTIFY-'.Str::uuid(), 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'pay_date' => '2026-11-05', 'pay_frequency' => 'monthly', 'status' => 'open']);
        $run = PayrollRun::query()->create(['payroll_period_id' => $period->id, 'run_number' => 1, 'status' => 'finalized']);

        return PayrollEntry::query()->create(['payroll_run_id' => $run->id, 'employee_id' => $this->employee()->id, 'basic_salary' => '1000.00', 'pay_frequency' => 'monthly', 'currency' => 'PHP', 'gross_pay' => '1000.00', 'total_deductions' => '0.00', 'net_pay' => '1000.00', 'status' => 'draft']);
    }
}
