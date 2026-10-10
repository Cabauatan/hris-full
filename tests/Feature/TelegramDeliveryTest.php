<?php

namespace Tests\Feature;

use App\Exceptions\TelegramRequestException;
use App\Jobs\DeliverHrisTelegram;
use App\Models\Applicant;
use App\Models\JobApplication;
use App\Models\TelegramConnection;
use App\Models\TelegramDelivery;
use App\Services\Notification\TelegramNotificationService;
use App\Services\Telegram\TelegramConnectionService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class TelegramDeliveryTest extends FormRequestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['telegram.enabled' => true, 'telegram.bot_token' => '12345:test_token', 'telegram.bot_username' => 'example_bot', 'telegram.bot_id' => 12345,
            'telegram.frontend_url' => 'http://127.0.0.1:5002', 'queue.default' => 'database']);
    }

    public function test_delivery_requires_opt_in_and_deduplicates_the_same_event(): void
    {
        $user = $this->user();
        $service = app(TelegramNotificationService::class);
        $service->queue('user', $user->id, 'password_changed', $user);
        $this->assertDatabaseCount('telegram_deliveries', 0);
        $connection = TelegramConnection::factory()->connected()->create(['recipient_id' => $user->id]);
        $service->queue('user', $user->id, 'password_changed', $user, 'one-event');
        $service->queue('user', $user->id, 'password_changed', $user, 'one-event');

        $this->assertDatabaseCount('telegram_deliveries', 1);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame($connection->connection_key, TelegramDelivery::query()->firstOrFail()->connection_key);
        $payload = DB::table('jobs')->value('payload');
        $command = unserialize(json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['data']['command']);
        $this->assertInstanceOf(DeliverHrisTelegram::class, $command);
        $this->assertTrue(Str::isUuid($command->deliveryId));
        $this->assertStringNotContainsString('12345:test_token', $payload);
        $this->assertStringNotContainsString('chat_id', $payload);
        $this->assertStringNotContainsString('Your HRIS password', $payload);
    }

    public function test_transaction_rollback_discards_delivery_and_after_commit_job(): void
    {
        $user = $this->user();
        TelegramConnection::factory()->connected()->create(['recipient_id' => $user->id]);
        DB::beginTransaction();
        app(TelegramNotificationService::class)->queue('user', $user->id, 'password_changed', $user);
        $this->assertDatabaseCount('telegram_deliveries', 1);
        $this->assertDatabaseCount('jobs', 0);

        DB::rollBack();

        $this->assertDatabaseCount('telegram_deliveries', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_committed_job_sends_plain_safe_message_without_an_email_address_and_retry_is_deduplicated(): void
    {
        $this->fakeTelegram();
        $user = $this->user();
        $user->update(['email' => 'invalid-email']);
        TelegramConnection::factory()->connected('777')->create(['recipient_id' => $user->id]);
        DB::transaction(fn () => app(TelegramNotificationService::class)->queue('user', $user->id, 'password_changed', $user));
        $delivery = TelegramDelivery::query()->firstOrFail();
        $this->assertDatabaseCount('jobs', 1);

        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'notifications', '--once' => true, '--sleep' => 0])->assertExitCode(0);
        app(TelegramNotificationService::class)->deliver($delivery->id);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage') && $request['chat_id'] === '777'
            && str_contains($request['text'], 'http://127.0.0.1:5002/profile') && ! isset($request['parse_mode']));
        Http::assertSentCount(2);
        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->sent_at);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    #[DataProvider('staleRecipients')]
    public function test_delivery_rechecks_consent_actor_bot_identity_and_configuration(string $change): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/bot12345:test_token/*' => Http::response(['ok' => true, 'result' => []])]);
        $user = $this->user();
        $connection = TelegramConnection::factory()->connected()->create(['recipient_id' => $user->id]);
        $service = app(TelegramNotificationService::class);
        $service->queue('user', $user->id, 'password_changed', $user);
        $delivery = TelegramDelivery::query()->firstOrFail();
        match ($change) {
            'unlinked' => app(TelegramConnectionService::class)->unlink($user),
            'relinked' => $connection->update(['connection_key' => (string) Str::uuid(), 'chat_id' => '888']),
            'inactive' => $user->update(['is_active' => false]),
            'deleted' => $user->delete(),
            'disabled' => config(['telegram.enabled' => false]),
            'bot_changed' => config(['telegram.bot_id' => 54321]),
        };

        $service->deliver($delivery->id);

        Http::assertNothingSent();
        $this->assertSame('skipped', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->sent_at);
    }

    public static function staleRecipients(): array
    {
        return [['unlinked'], ['relinked'], ['inactive'], ['deleted'], ['disabled'], ['bot_changed']];
    }

    public function test_wrong_applicant_and_stale_application_state_do_not_receive_details(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/bot12345:test_token/*' => Http::response(['ok' => true, 'result' => []])]);
        $application = JobApplication::factory()->approved()->create(['cover_letter' => 'Private applicant note']);
        $other = Applicant::factory()->create();
        TelegramConnection::factory()->connected()->create(['recipient_type' => 'applicant', 'recipient_id' => $other->id]);
        $service = app(TelegramNotificationService::class);
        $service->queue('job_application', $application->id, 'approved', $other, 'wrong-owner');
        $service->deliver(TelegramDelivery::query()->where('event_id', 'wrong-owner')->firstOrFail()->id);
        TelegramConnection::factory()->connected()->create(['recipient_type' => 'applicant', 'recipient_id' => $application->applicant_id]);
        $service->queue('job_application', $application->id, 'approved', $application->applicant, 'stale');
        $application->update(['status' => 'rejected']);
        $service->deliver(TelegramDelivery::query()->where('event_id', 'stale')->firstOrFail()->id);

        Http::assertNothingSent();
        $this->assertSame(2, TelegramDelivery::query()->where('status', 'skipped')->count());
    }

    public function test_application_alert_uses_generic_copy_without_private_fields(): void
    {
        $this->fakeTelegram();
        $application = JobApplication::factory()->approved()->create(['cover_letter' => 'Private cover letter', 'remarks' => 'Private remarks']);
        TelegramConnection::factory()->connected()->create(['recipient_type' => 'applicant', 'recipient_id' => $application->applicant_id]);
        $service = app(TelegramNotificationService::class);
        $service->queue('job_application', $application->id, 'approved', $application->applicant);

        $service->deliver(TelegramDelivery::query()->firstOrFail()->id);

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/sendMessage')) {
                return false;
            }
            $this->assertStringNotContainsString('Private', $request['text']);
            $this->assertStringContainsString('/applicant/applications', $request['text']);

            return true;
        });
    }

    public function test_http_200_api_error_is_sanitized_and_never_marked_sent(): void
    {
        $this->fakeTelegram(['ok' => false, 'error_code' => 500, 'description' => 'Secret token and response content']);
        [$service, $delivery] = $this->queuedSecurityAlert();
        try {
            $service->deliver($delivery->id);
            $this->fail('API errors must not report delivery success.');
        } catch (TelegramRequestException $exception) {
            $this->assertSame(500, $exception->errorCode);
            $this->assertStringNotContainsString('Secret', $exception->getMessage());
            $this->assertStringNotContainsString('test_token', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $this->assertSame('queued', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->sent_at);
        $service->failed($delivery->id);
        $this->assertSame('failed', $delivery->fresh()->status);
    }

    public function test_blocked_bot_opts_out_every_account_linked_to_the_chat(): void
    {
        $this->fakeTelegram(['ok' => false, 'error_code' => 403], 403);
        [$service, $delivery] = $this->queuedSecurityAlert();
        $applicant = Applicant::factory()->create();
        TelegramConnection::factory()->connected('777')->create(['recipient_type' => 'applicant', 'recipient_id' => $applicant->id]);

        $service->deliver($delivery->id);

        $this->assertSame('skipped', $delivery->fresh()->status);
        $this->assertSame(2, TelegramConnection::query()->whereNull('chat_id')->whereNotNull('unsubscribed_at')->count());
        $this->assertSame(2, TelegramConnection::query()->pluck('connection_key')->unique()->count());
    }

    public function test_rate_limit_releases_job_for_server_delay_without_marking_delivery_sent(): void
    {
        $this->fakeTelegram(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 47]], 429);
        [$service, $delivery] = $this->queuedSecurityAlert();
        $job = (new DeliverHrisTelegram($delivery->id))->withFakeQueueInteractions();

        $job->handle($service);

        $job->assertReleased(47);
        $this->assertSame('queued', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->sent_at);
        Http::assertSentCount(2);
    }

    public function test_token_for_a_different_bot_cannot_use_old_consent(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/bot12345:test_token/getMe' => Http::response(['ok' => true, 'result' => ['id' => 54321, 'username' => 'other_bot', 'is_bot' => true]])]);
        [$service, $delivery] = $this->queuedSecurityAlert();
        try {
            $service->deliver($delivery->id);
            $this->fail('Mismatched bot identity must not send.');
        } catch (TelegramRequestException $exception) {
            $this->assertSame('identity', $exception->category);
        }

        Http::assertSentCount(1);
        $this->assertSame('queued', $delivery->fresh()->status);
    }

    public function test_connection_failure_is_sanitized_without_an_immediate_retry(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/bot12345:test_token/getMe' => Http::response(['ok' => true, 'result' => ['id' => 12345, 'username' => 'example_bot', 'is_bot' => true]]),
            'https://api.telegram.org/bot12345:test_token/sendMessage' => Http::failedConnection('Secret URL or private message'),
        ]);
        [$service, $delivery] = $this->queuedSecurityAlert();
        try {
            $service->deliver($delivery->id);
            $this->fail('Connection failure must remain retryable.');
        } catch (TelegramRequestException $exception) {
            $this->assertSame('connection', $exception->category);
            $this->assertStringNotContainsString('Secret', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $this->assertSame('queued', $delivery->fresh()->status);
    }

    public function test_transport_failure_with_response_discards_private_response_and_previous_exception(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/bot12345:test_token/getMe' => Http::response(['ok' => true, 'result' => ['id' => 12345, 'username' => 'example_bot', 'is_bot' => true]]),
            'https://api.telegram.org/bot12345:test_token/sendMessage' => fn () => throw Http::failedRequest('Secret URL or private response body', 502),
        ]);
        [$service, $delivery] = $this->queuedSecurityAlert();
        try {
            $service->deliver($delivery->id);
            $this->fail('Transport failure with response must remain sanitized and retryable.');
        } catch (TelegramRequestException $exception) {
            $this->assertSame('transport', $exception->category);
            $this->assertSame(502, $exception->errorCode);
            $this->assertStringNotContainsString('Secret', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $this->assertSame('queued', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->sent_at);
    }

    private function queuedSecurityAlert(): array
    {
        $user = $this->user();
        TelegramConnection::factory()->connected('777')->create(['recipient_id' => $user->id]);
        $service = app(TelegramNotificationService::class);
        $service->queue('user', $user->id, 'password_changed', $user);

        return [$service, TelegramDelivery::query()->firstOrFail()];
    }

    private function fakeTelegram(?array $sendResult = null, int $status = 200): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/bot12345:test_token/getMe' => Http::response(['ok' => true, 'result' => ['id' => 12345, 'username' => 'example_bot', 'is_bot' => true]]),
            'https://api.telegram.org/bot12345:test_token/sendMessage' => Http::response($sendResult ?? ['ok' => true, 'result' => ['message_id' => 1]], $status),
        ]);
    }
}
