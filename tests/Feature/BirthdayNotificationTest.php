<?php

namespace Tests\Feature;

use App\Jobs\DeliverBirthdayNotification;
use App\Jobs\DeliverHrisEmail;
use App\Jobs\DeliverHrisTelegram;
use App\Models\Applicant;
use App\Models\Department;
use App\Models\Division;
use App\Models\Employee;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\HrisNotification;
use App\Services\Notification\BirthdayNotificationService;
use App\Services\Notification\HrisMessageService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\FormRequestTestCase;

class BirthdayNotificationTest extends FormRequestTestCase
{
    #[TestWith(['2026-10-17 07:59:59', 0])]
    #[TestWith(['2026-10-17 08:00:00', 1])]
    #[TestWith(['2026-10-17 23:59:59', 1])]
    public function test_daily_scan_waits_until_eight_am_in_the_application_timezone(string $time, int $expected): void
    {
        $this->travelTo($time);
        $position = $this->position($this->department());
        $birthday = $this->employee(null, ['position_id' => $position, 'birth_date' => '1990-10-17']);
        $this->employee(null, ['position_id' => $position, 'birth_date' => '1990-10-16']);
        $this->employee(null, ['position_id' => $position, 'birth_date' => '1990-11-17']);
        $this->employee(null, ['position_id' => $position, 'birth_date' => '2026-10-17']);
        $this->employee(null, ['position_id' => $position, 'birth_date' => '2027-10-17']);
        $this->employee(null, ['position_id' => $position, 'birth_date' => '1990-10-17', 'is_active' => false]);
        $this->employee(null, ['position_id' => $position]);
        $this->employee(null, ['birth_date' => '1990-10-17']);
        Queue::fake([DeliverBirthdayNotification::class]);

        $queued = app(BirthdayNotificationService::class)->queueDue();

        $this->assertSame($expected, $queued);
        Queue::assertPushed(DeliverBirthdayNotification::class, $expected);
        if ($expected > 0) {
            Queue::assertPushed(DeliverBirthdayNotification::class, fn (DeliverBirthdayNotification $job): bool => $job->employeeId === $birthday->id
                && $job->birthdayDate === '2026-10-17' && $job->queue === 'notifications' && $job->afterCommit === true);
        }
    }

    public function test_command_queues_due_birthdays_on_the_notifications_worker(): void
    {
        $this->travelTo('2026-10-17 08:05:00');
        $birthday = $this->employee(null, ['position_id' => $this->position($this->department()), 'birth_date' => '1990-10-17']);
        Queue::fake([DeliverBirthdayNotification::class]);

        $this->artisan('hris:notify-birthdays')
            ->expectsOutputToContain('Queued birthday notifications for 1 employee(s).')->assertExitCode(0);

        Queue::assertPushedOn('notifications', DeliverBirthdayNotification::class, fn (DeliverBirthdayNotification $job): bool => $job->employeeId === $birthday->id);
    }

    public function test_birthday_recipients_are_only_active_linked_coworkers_in_the_department_or_division(): void
    {
        $this->travelTo('2026-10-17 08:00:00');
        $division = $this->division();
        $department = $this->department($division);
        $birthdayUser = $this->user();
        $birthday = $this->employee($birthdayUser, ['position_id' => $this->position($department), 'birth_date' => '1990-10-17']);
        $sameDepartment = $this->coworker($department);
        $sameDivision = $this->coworker($this->department($division));
        $this->coworker($this->department($this->division()));
        $this->coworker($department, ['is_active' => false]);
        $inactiveUser = $this->coworker($department);
        $inactiveUser->update(['is_active' => false]);
        $this->employee($this->user());
        $this->employee(null, ['position_id' => $this->position($department)]);
        $this->employee($this->user(), ['position_id' => $this->position($department, false)]);
        $this->coworker($this->department($division, false));
        $this->coworker($this->department());
        $this->user('employees.view');

        app(BirthdayNotificationService::class)->deliver(new DeliverBirthdayNotification($birthday->id, '2026-10-17'));

        $this->assertSame([$sameDepartment->id, $sameDivision->id], DatabaseNotification::query()->orderBy('notifiable_id')->pluck('notifiable_id')->all());
        $this->assertSame(0, $birthdayUser->notifications()->count());
    }

    public function test_inactive_division_still_notifies_same_department_but_does_not_share_across_departments(): void
    {
        $this->travelTo('2026-10-17 08:00:00');
        $division = $this->division(false);
        $department = $this->department($division);
        $birthday = $this->employee(null, ['position_id' => $this->position($department), 'birth_date' => '1990-10-17']);
        $sameDepartment = $this->coworker($department);
        $this->coworker($this->department($division));

        app(BirthdayNotificationService::class)->deliver(new DeliverBirthdayNotification($birthday->id, '2026-10-17'));

        $this->assertSame([$sameDepartment->id], DatabaseNotification::query()->pluck('notifiable_id')->all());
    }

    public function test_retry_preserves_read_state_and_deduplicates_inbox_email_and_linked_telegram_deliveries(): void
    {
        $this->travelTo('2026-10-17 08:00:00');
        [$birthday, $recipient] = $this->birthdayAndRecipient();
        config(['hris_mail.enabled' => true, 'telegram.enabled' => true, 'telegram.bot_id' => 12345,
            'telegram.bot_username' => 'hris_test_bot', 'telegram.bot_token' => '12345:test_token']);
        TelegramConnection::factory()->connected()->create(['recipient_id' => $recipient->id]);
        Queue::fake([DeliverHrisEmail::class, DeliverHrisTelegram::class]);
        Mail::fake();
        Http::preventStrayRequests();
        $service = app(BirthdayNotificationService::class);
        $job = new DeliverBirthdayNotification($birthday->id, '2026-10-17');
        $service->deliver($job);
        $notification = $recipient->notifications()->sole();
        $notification->markAsRead();
        $this->travelTo('2026-10-17 10:00:00');

        $service->deliver($job);

        $this->assertSame(1, $recipient->notifications()->count());
        $this->assertSame('2026-10-17 08:00:00', $notification->fresh()->read_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-17 08:00:00', $notification->fresh()->created_at->format('Y-m-d H:i:s'));
        $this->assertSame(['resource_type' => 'birthday', 'resource_id' => $birthday->id, 'event' => 'birthday',
            'occurred_at' => '2026-10-17T08:00:00+08:00'], $notification->data);
        $this->assertDatabaseCount('email_deliveries', 1);
        $this->assertDatabaseHas('email_deliveries', ['resource_type' => 'birthday', 'resource_id' => $birthday->id,
            'recipient_id' => $recipient->id, 'source_version' => '2026-10-17', 'status' => 'queued']);
        $this->assertDatabaseCount('telegram_deliveries', 1);
        $this->assertDatabaseHas('telegram_deliveries', ['resource_type' => 'birthday', 'resource_id' => $birthday->id,
            'recipient_id' => $recipient->id, 'source_version' => '2026-10-17', 'status' => 'queued']);
        Queue::assertPushed(DeliverHrisEmail::class, 1);
        Queue::assertPushed(DeliverHrisTelegram::class, 1);
        Mail::assertNothingSent();
    }

    public function test_large_department_delivery_fans_out_into_bounded_recipient_batches(): void
    {
        $this->travelTo('2026-10-17 08:00:00');
        $department = $this->department();
        $birthday = $this->employee(null, ['position_id' => $this->position($department), 'birth_date' => '1990-10-17']);
        $recipientIds = [];
        for ($index = 0; $index < 101; $index++) {
            $recipientIds[] = $this->coworker($department)->employee()->value('id');
        }
        Queue::fake([DeliverBirthdayNotification::class]);

        app(BirthdayNotificationService::class)->deliver(new DeliverBirthdayNotification($birthday->id, '2026-10-17'));

        $jobs = Queue::pushed(DeliverBirthdayNotification::class);
        $this->assertSame([100, 1], $jobs->map(fn (DeliverBirthdayNotification $job): int => count($job->recipientIds))->all());
        $this->assertSame($recipientIds, $jobs->flatMap(fn (DeliverBirthdayNotification $job): array => $job->recipientIds)->all());
        Queue::assertPushed(DeliverBirthdayNotification::class, 2);
        $this->assertDatabaseCount('notifications', 0);
    }

    #[TestWith(['2026-02-28 08:00:00', 1])]
    #[TestWith(['2026-03-01 08:00:00', 0])]
    #[TestWith(['2028-02-28 08:00:00', 0])]
    #[TestWith(['2028-02-29 08:00:00', 1])]
    public function test_leap_day_birthdays_use_the_calendar_observance_rule(string $time, int $expected): void
    {
        $this->travelTo($time);
        $this->employee(null, ['position_id' => $this->position($this->department()), 'birth_date' => '2000-02-29']);
        Queue::fake([DeliverBirthdayNotification::class]);

        $queued = app(BirthdayNotificationService::class)->queueDue();

        $this->assertSame($expected, $queued);
        Queue::assertPushed(DeliverBirthdayNotification::class, $expected);
    }

    #[TestWith(['stale'])]
    #[TestWith(['inactive_birthday_employee'])]
    #[TestWith(['changed_birth_date'])]
    #[TestWith(['inactive_recipient'])]
    #[TestWith(['transferred_recipient'])]
    public function test_delivery_rechecks_the_occurrence_and_current_recipient_eligibility(string $change): void
    {
        $this->travelTo('2026-10-17 08:00:00');
        [$birthday, $recipient] = $this->birthdayAndRecipient();
        $job = new DeliverBirthdayNotification($birthday->id, '2026-10-17', [$recipient->employee()->value('id')]);
        match ($change) {
            'stale' => $this->travelTo('2026-10-18 08:00:00'),
            'inactive_birthday_employee' => $birthday->update(['is_active' => false]),
            'changed_birth_date' => $birthday->update(['birth_date' => '1990-10-18']),
            'inactive_recipient' => $recipient->update(['is_active' => false]),
            'transferred_recipient' => $recipient->employee()->update(['position_id' => $this->position($this->department())]),
        };

        app(BirthdayNotificationService::class)->deliver($job);

        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertDatabaseCount('telegram_deliveries', 0);
    }

    public function test_permissionless_coworker_inbox_uses_safe_birthday_details_and_rechecks_membership(): void
    {
        $this->travelTo('2026-10-17 08:00:00');
        [$birthday, $recipient] = $this->birthdayAndRecipient();
        app(BirthdayNotificationService::class)->deliver(new DeliverBirthdayNotification($birthday->id, '2026-10-17'));
        $notification = $recipient->notifications()->sole();

        $response = $this->actingAs($recipient)->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.0.title', 'Birthday · Alicia Birthday')
            ->assertJsonPath('data.0.message', 'Alicia Birthday celebrates their birthday on October 17, 2026 (turning 36).')
            ->assertJsonPath('data.0.category', 'birthday')->assertJsonPath('data.0.event', 'birthday')
            ->assertJsonPath('data.0.source', ['module' => 'calendar', 'scope' => 'visible']);
        $this->assertStringNotContainsString('1990-10-17', $response->getContent());
        $this->assertStringNotContainsString('birth_date', $response->getContent());
        $this->getJson('/api/v1/employees/'.$birthday->id)->assertForbidden();
        $this->actingAs($this->user('employees.view'))->patchJson('/api/v1/notifications/'.$notification->id.'/read')->assertNotFound();
        $this->assertNull($notification->fresh()->read_at);

        $recipient->employee()->update(['position_id' => $this->position($this->department())]);
        $this->actingAs($recipient)->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.0.title', 'HRIS update')->assertJsonPath('data.0.source', null);
    }

    public function test_outbound_message_resolver_rejects_applicants_unrelated_users_and_stale_occurrences(): void
    {
        $this->travelTo('2026-10-17 08:00:00');
        [$birthday, $recipient] = $this->birthdayAndRecipient();
        $service = app(HrisMessageService::class);

        $message = $service->resolve('birthday', $birthday->id, 'birthday', $recipient, '2026-10-17');

        $this->assertSame(['title' => 'Birthday · Alicia Birthday',
            'message' => 'Alicia Birthday celebrates their birthday on October 17, 2026 (turning 36).',
            'path' => '/calendar?scope=visible&types=birthday'], $message);
        $this->assertNull($service->resolve('birthday', $birthday->id, 'birthday', Applicant::factory()->create(), '2026-10-17'));
        $this->assertNull($service->resolve('birthday', $birthday->id, 'birthday', $this->user('employees.view'), '2026-10-17'));
        $this->assertNull($service->resolve('birthday', $birthday->id, 'birthday', $recipient, '2026-10-16'));
        $recipient->employee()->update(['position_id' => $this->position($this->department())]);
        $this->assertNull($service->resolve('birthday', $birthday->id, 'birthday', $recipient, '2026-10-17'));
    }

    public function test_inbox_redacts_birthday_when_invalid_occurrence_timestamp_overflows_to_today(): void
    {
        $this->travelTo('2026-03-03 08:00:00');
        [$birthday, $recipient] = $this->birthdayAndRecipient();
        $birthday->update(['birth_date' => '1990-03-03']);
        $recipient->notifications()->create([
            'id' => (string) Str::uuid(), 'type' => HrisNotification::class,
            'data' => ['resource_type' => 'birthday', 'resource_id' => $birthday->id,
                'event' => 'birthday', 'occurred_at' => '2026-02-31T08:00:00+08:00'],
        ]);

        $response = $this->actingAs($recipient)->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.0.title', 'HRIS update')->assertJsonPath('data.0.source', null);

        $this->assertStringNotContainsString('Alicia', $response->getContent());
        $this->assertStringNotContainsString('turning 36', $response->getContent());
    }

    /** @return array{Employee, User} */
    private function birthdayAndRecipient(): array
    {
        $department = $this->department();
        $birthday = $this->employee(null, ['position_id' => $this->position($department), 'birth_date' => '1990-10-17',
            'first_name' => 'Alicia', 'last_name' => 'Birthday']);

        return [$birthday, $this->coworker($department)];
    }

    private function coworker(Department $department, array $attributes = []): User
    {
        $user = $this->user();
        $this->employee($user, array_replace(['position_id' => $this->position($department)], $attributes));

        return $user;
    }

    private function division(bool $active = true): Division
    {
        return Division::query()->create(['code' => (string) Str::uuid(), 'name' => 'Test division', 'is_active' => $active]);
    }

    private function department(?Division $division = null, bool $active = true): Department
    {
        return Department::query()->create(['division_id' => $division?->id, 'code' => (string) Str::uuid(), 'name' => 'Test department', 'is_active' => $active]);
    }

    private function position(Department $department, bool $active = true): int
    {
        return (int) $department->positions()->create(['code' => (string) Str::uuid(), 'name' => 'Test position', 'is_active' => $active])->id;
    }
}
