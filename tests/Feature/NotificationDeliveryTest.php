<?php

namespace Tests\Feature;

use App\Jobs\DeliverHrisNotification;
use App\Models\Announcement;
use App\Models\ApprovalDelegation;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use App\Notifications\HrisNotification;
use App\Services\Notification\NotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class NotificationDeliveryTest extends FormRequestTestCase
{
    #[DataProvider('workflowTypes')]
    public function test_pending_delivery_uses_the_existing_supervisor_authority_and_safe_payload(string $type, string $permission): void
    {
        $supervisor = $this->user($permission.'.view', $permission.'.approve');
        $supervisorEmployee = $this->employee($supervisor);
        $owner = $this->user($permission.'.view');
        $employee = $this->employee($owner, ['supervisor_employee_id' => $supervisorEmployee->id]);
        $record = $this->workflow($type, $employee, $owner);
        $job = $this->job($type, $record);

        $job->handle(app(NotificationService::class));

        $this->assertSame(1, $supervisor->notifications()->count());
        $this->assertSame(0, $owner->notifications()->count());
        $notification = $supervisor->notifications()->firstOrFail();
        $this->assertSame('user', $notification->notifiable_type);
        $this->assertSame(HrisNotification::class, $notification->type);
        $this->assertSame(['resource_type' => $type, 'resource_id' => $record->id, 'event' => 'pending', 'occurred_at' => $job->occurredAt], $notification->data);
        $this->assertStringNotContainsString('Private', json_encode($notification->data, JSON_THROW_ON_ERROR));
    }

    public static function workflowTypes(): array
    {
        return [['leave', 'leave'], ['cto', 'cto'], ['overtime', 'overtime'], ['official_business', 'official-business'], ['travel_order', 'travel-orders'], ['attendance_correction', 'attendance-corrections']];
    }

    public function test_delegate_and_permissions_are_resolved_when_the_job_runs(): void
    {
        $this->freezeTime();
        $supervisor = $this->user('cto.view', 'cto.approve');
        $supervisorEmployee = $this->employee($supervisor);
        $owner = $this->user('cto.view');
        $employee = $this->employee($owner, ['supervisor_employee_id' => $supervisorEmployee->id]);
        $record = $this->workflow('cto', $employee, $owner);
        $job = $this->job('cto', $record);
        $delegate = $this->user('cto.view', 'cto.approve');
        ApprovalDelegation::query()->create(['delegator_user_id' => $supervisor->id, 'delegate_user_id' => $delegate->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'is_active' => true]);

        $job->handle(app(NotificationService::class));

        $this->assertSame(0, $supervisor->notifications()->count());
        $this->assertSame(1, $delegate->notifications()->count());
    }

    #[DataProvider('lostReviewerAccess')]
    public function test_delivery_skips_inactive_or_unqualified_reviewers(string $change): void
    {
        $reviewer = $this->user('cto.view', 'cto.approve');
        $supervisor = $this->employee($reviewer);
        $owner = $this->user('cto.view');
        $employee = $this->employee($owner, ['supervisor_employee_id' => $supervisor->id]);
        $record = $this->workflow('cto', $employee, $owner);
        $job = $this->job('cto', $record);
        if ($change === 'inactive') {
            $reviewer->update(['is_active' => false]);
        } elseif ($change === 'resolved') {
            $record->update(['status' => 'approved']);
        } else {
            $permission = $reviewer->permissions()->where('code', 'cto.'.$change)->firstOrFail();
            $reviewer->permissions()->detach($permission);
        }

        $job->handle(app(NotificationService::class));

        $this->assertSame(0, $reviewer->notifications()->count());
    }

    public static function lostReviewerAccess(): array
    {
        return [['inactive'], ['view'], ['approve'], ['resolved']];
    }

    public function test_pending_profile_change_only_notifies_reviewers_who_can_view_the_record(): void
    {
        $owner = $this->user('profile-changes.view');
        $employee = $this->employee($owner);
        $hr = $this->user('profile-changes.view', 'profile-changes.approve', 'employees.view');
        $blindReviewer = $this->user('profile-changes.approve');
        $outOfScope = $this->user('profile-changes.view', 'profile-changes.approve');
        $record = $this->workflow('profile_changes', $employee, $owner);

        $this->job('profile_changes', $record)->handle(app(NotificationService::class));

        $this->assertSame(1, $hr->notifications()->count());
        $this->assertSame(0, $blindReviewer->notifications()->count());
        $this->assertSame(0, $outOfScope->notifications()->count());
        $this->assertSame(0, $owner->notifications()->count());
    }

    #[DataProvider('outcomeEvents')]
    public function test_outcomes_notify_owner_and_authorized_creator_without_private_values(string $event): void
    {
        $owner = $this->user('cto.view');
        $employee = $this->employee($owner);
        $creator = $this->user('cto.view', 'employees.view');
        $record = $this->workflow('cto', $employee, $creator);
        $record->update(['status' => $event]);

        $this->job('cto', $record, $event)->handle(app(NotificationService::class));

        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame(1, $creator->notifications()->count());
        $dto = app(NotificationService::class)->present($owner, $owner->notifications()->firstOrFail());
        $this->assertSame('mine', $dto['source']['scope']);
        $this->assertSame($event, $dto['event']);
        $this->assertStringNotContainsString('Private', json_encode($dto, JSON_THROW_ON_ERROR));
    }

    public static function outcomeEvents(): array
    {
        return [['approved'], ['rejected'], ['cancelled']];
    }

    public function test_retry_deduplicates_delivery_and_preserves_read_timestamp(): void
    {
        $owner = $this->user('cto.view');
        $record = $this->workflow('cto', $this->employee($owner), $owner);
        $job = $this->job('cto', $record, 'approved');
        $job->handle(app(NotificationService::class));
        $notification = $owner->notifications()->firstOrFail();
        $notification->markAsRead();
        $readAt = $notification->read_at->toIso8601String();

        $job->handle(app(NotificationService::class));

        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame($readAt, $notification->fresh()->read_at->toIso8601String());
    }

    public function test_inaccessible_and_unknown_notification_payloads_are_redacted(): void
    {
        $owner = $this->user('cto.view');
        $record = $this->workflow('cto', $this->employee($owner), $owner);
        $this->job('cto', $record, 'approved')->handle(app(NotificationService::class));
        $owner->permissions()->detach();
        $notification = $owner->notifications()->firstOrFail();
        $notification->update(['data' => $notification->data + ['title' => 'Private title', 'message' => 'Private reason', 'url' => 'https://evil.test']]);

        $dto = app(NotificationService::class)->present($owner, $notification->fresh());

        $this->assertNull($dto['source']);
        $this->assertSame('HRIS update', $dto['title']);
        $this->assertStringNotContainsString('Private', json_encode($dto, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('evil.test', json_encode($dto, JSON_THROW_ON_ERROR));
    }

    public function test_announcements_only_deliver_to_current_readers_when_publication_is_visible(): void
    {
        $this->freezeTime();
        $reader = $this->user('announcements.view');
        $manager = $this->user('announcements.manage');
        $plain = $this->user();
        $announcement = Announcement::query()->create(['title' => 'Private announcement title', 'content' => 'Private content', 'priority' => 'normal', 'is_active' => true, 'published_at' => now(), 'expires_at' => now()->addDay()]);
        $job = $this->job('announcement', $announcement, 'published', $announcement->published_at->format('Y-m-d H:i:s'));

        $job->handle(app(NotificationService::class));

        $this->assertSame(1, $reader->notifications()->count());
        $this->assertSame(1, $manager->notifications()->count());
        $this->assertSame(0, $plain->notifications()->count());
        $this->assertStringNotContainsString('Private', json_encode($reader->notifications()->firstOrFail()->data, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('unavailableAnnouncements')]
    public function test_stale_or_unpublished_announcement_jobs_do_not_deliver(string $state): void
    {
        $this->freezeTime();
        $reader = $this->user('announcements.view', 'announcements.manage');
        $announcement = Announcement::query()->create(['title' => 'Notice', 'content' => 'Notice body', 'priority' => 'normal', 'is_active' => true, 'published_at' => now()]);
        $job = $this->job('announcement', $announcement, 'published', $announcement->published_at->format('Y-m-d H:i:s'));
        $announcement->update(match ($state) {
            'rescheduled' => ['published_at' => now()->addHour()],
            'cancelled' => ['published_at' => null],
            'inactive' => ['is_active' => false],
            'expired' => ['expires_at' => now()],
        });

        $job->handle(app(NotificationService::class));

        $this->assertSame(0, $reader->notifications()->count());
    }

    public static function unavailableAnnouncements(): array
    {
        return [['rescheduled'], ['cancelled'], ['inactive'], ['expired']];
    }

    public function test_rollback_discards_the_database_queue_job(): void
    {
        $owner = $this->user('cto.view');
        $record = $this->workflow('cto', $this->employee($owner), $owner);
        config(['queue.default' => 'database']);
        DB::beginTransaction();
        app(NotificationService::class)->queue($record, 'approved');
        $this->assertSame(0, DB::table('jobs')->count());

        DB::rollBack();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, $owner->notifications()->count());
    }

    public function test_committed_job_is_processed_by_the_real_database_queue_worker(): void
    {
        $owner = $this->user('cto.view');
        $record = $this->workflow('cto', $this->employee($owner), $owner);
        config(['queue.default' => 'database']);
        DB::transaction(fn () => app(NotificationService::class)->queue($record, 'approved'));
        $this->assertSame(1, DB::table('jobs')->where('queue', 'notifications')->count());
        $this->assertSame(0, $owner->notifications()->count());

        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'notifications', '--once' => true, '--memory' => 512, '--sleep' => 0])->assertExitCode(0);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, $owner->notifications()->count());
    }

    public function test_future_announcement_job_is_delayed_until_the_publication_time(): void
    {
        $this->freezeTime();
        $reader = $this->user('announcements.view');
        $announcement = Announcement::query()->create(['title' => 'Scheduled', 'content' => 'Body', 'priority' => 'normal', 'is_active' => true, 'published_at' => now()->addHours(2)]);
        config(['queue.default' => 'database']);

        DB::transaction(fn () => app(NotificationService::class)->queue($announcement, 'published'));

        $this->assertSame($announcement->published_at->timestamp, DB::table('jobs')->value('available_at'));
        $this->assertSame(0, DB::table('notifications')->count());

        $this->travelTo($announcement->published_at);
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'notifications', '--stop-when-empty' => true, '--memory' => 512, '--sleep' => 0])->assertExitCode(0);
        $this->assertSame($announcement->published_at->timestamp, $reader->notifications()->firstOrFail()->created_at->timestamp);
    }

    public function test_repeated_announcement_fanout_reaches_every_reader_once_through_the_database_queue(): void
    {
        $this->freezeTime();
        $reader = $this->user('announcements.view');
        $permissionId = $reader->permissions()->value('permissions.id');
        $others = User::factory()->count(104)->state(fn () => ['uuid' => (string) Str::uuid(), 'is_active' => true])->create();
        foreach ($others as $user) {
            $user->permissions()->attach($permissionId);
        }
        $announcement = Announcement::query()->create(['title' => 'Notice', 'content' => 'Notice body', 'priority' => 'normal', 'is_active' => true, 'published_at' => now()]);
        config(['queue.default' => 'database']);
        DB::transaction(function () use ($announcement): void {
            app(NotificationService::class)->queue($announcement, 'published');
            app(NotificationService::class)->queue($announcement, 'published');
        });

        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'notifications', '--stop-when-empty' => true, '--memory' => 512, '--sleep' => 0])->assertExitCode(0);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(105, DB::table('notifications')->count());
        $this->assertSame(1, $reader->notifications()->count());
        $this->assertSame(1, $others->last()->notifications()->count());
    }

    public function test_published_payslip_only_notifies_its_employee_and_never_contains_pay_or_document_paths(): void
    {
        $owner = $this->user('payslips.view');
        $employee = $this->employee($owner);
        $finance = $this->user('payslips.view', 'employees.view');
        $period = PayrollPeriod::query()->create(['code' => 'NOTIFY', 'period_start' => '2026-10-01', 'period_end' => '2026-10-15', 'pay_date' => '2026-10-20', 'pay_frequency' => 'semi_monthly']);
        $run = PayrollRun::query()->create(['payroll_period_id' => $period->id, 'run_number' => 1, 'status' => 'finalized']);
        $entry = PayrollEntry::query()->create(['payroll_run_id' => $run->id, 'employee_id' => $employee->id, 'basic_salary' => '12345.67', 'pay_frequency' => 'semi_monthly', 'net_pay' => '12345.67']);
        $payslip = Payslip::query()->create(['payroll_entry_id' => $entry->id, 'employee_id' => $employee->id, 'file_path' => 'private/payroll/secret.pdf', 'generated_at' => now(), 'published_at' => now()]);

        $this->job('payslip', $payslip, 'published')->handle(app(NotificationService::class));

        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame(0, $finance->notifications()->count());
        $dto = app(NotificationService::class)->present($owner, $owner->notifications()->firstOrFail());
        $this->assertSame(['module' => 'payslips', 'id' => $payslip->id, 'scope' => 'mine'], $dto['source']);
        $this->assertStringNotContainsString('12345.67', json_encode($dto, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('secret.pdf', json_encode($dto, JSON_THROW_ON_ERROR));
    }

    public function test_deleted_source_job_is_safely_discarded(): void
    {
        $owner = $this->user('cto.view');
        $record = $this->workflow('cto', $this->employee($owner), $owner);
        $job = $this->job('cto', $record, 'approved');
        $record->delete();

        $job->handle(app(NotificationService::class));

        $this->assertSame(0, $owner->notifications()->count());
    }

    private function workflow(string $type, Employee $employee, User $creator): Model
    {
        $class = NotificationService::RESOURCES[$type]['class'];
        $base = ['employee_id' => $employee->id, 'created_by_user_id' => $creator->id, 'reason' => 'Private reason', 'status' => 'pending'];
        $specific = match ($type) {
            'leave' => ['leave_type_id' => LeaveType::query()->create(['code' => 'TEST'.Str::random(6), 'name' => 'Test leave', 'requires_balance' => false, 'is_active' => true])->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'requested_units' => '1.00'],
            'attendance_correction' => ['attendance_date' => '2026-10-05', 'requested_time_in' => '2026-10-05 08:00:00'],
            'profile_changes' => [],
            default => ['start_at' => '2026-10-05 08:00:00', 'end_at' => '2026-10-05 10:00:00', 'requested_minutes' => 120, 'purpose' => 'Private purpose', 'destination' => 'Private destination'],
        };

        return $class::query()->create(array_intersect_key($base + $specific, array_flip((new $class)->getFillable())));
    }

    private function job(string $type, Model $record, string $event = 'pending', ?string $version = null): DeliverHrisNotification
    {
        return new DeliverHrisNotification($type, $record->id, $event, (string) Str::uuid(), now()->toIso8601String(), $version);
    }
}
