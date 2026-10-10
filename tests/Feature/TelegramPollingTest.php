<?php

namespace Tests\Feature;

use App\Models\TelegramBotState;
use App\Models\TelegramConnection;
use App\Services\Telegram\TelegramConnectionService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class TelegramPollingTest extends FormRequestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['telegram.enabled' => true, 'telegram.bot_token' => '12345:test_token', 'telegram.bot_username' => 'example_bot', 'telegram.bot_id' => 12345]);
    }

    public function test_once_links_private_chat_persists_cursor_and_ignores_unsupported_updates(): void
    {
        $user = $this->user();
        $link = app(TelegramConnectionService::class)->createLink($user);
        parse_str((string) parse_url($link['url'], PHP_URL_QUERY), $query);
        $this->fakePolling([
            ['update_id' => 10, 'message' => ['chat' => ['id' => 777, 'type' => 'private'], 'from' => ['id' => 777, 'is_bot' => false], 'text' => '/start '.$query['start']]],
            ['update_id' => 11, 'message' => ['chat' => ['id' => -999, 'type' => 'group'], 'from' => ['id' => 777, 'is_bot' => false], 'text' => 'Private ignored message']],
        ]);

        $this->artisan('telegram:poll', ['--once' => true])->assertExitCode(0);

        $this->assertTrue(app(TelegramConnectionService::class)->status($user)['connected']);
        $this->assertSame(12, TelegramBotState::query()->findOrFail(12345)->next_update_id);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/getUpdates') && $request['offset'] === 0
            && $request['timeout'] === 0 && $request['allowed_updates'] === ['message']);
        Http::assertSentCount(3);
        $this->assertNull(Cache::get('hris-telegram-poll-12345'));
    }

    public function test_database_cache_polling_renews_within_same_second_and_releases_prefixed_lease(): void
    {
        $this->freezeTime();
        config(['cache.default' => 'database', 'cache.prefix' => 'telegram-test:']);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/bot12345:test_token/getMe' => function () {
                $this->assertDatabaseHas('cache_locks', ['key' => 'telegram-test:hris-telegram-poll-12345', 'expiration' => now()->timestamp + 90]);

                return Http::response(['ok' => true, 'result' => ['id' => 12345, 'username' => 'example_bot', 'is_bot' => true]]);
            },
            'https://api.telegram.org/bot12345:test_token/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '']]),
            'https://api.telegram.org/bot12345:test_token/getUpdates' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->artisan('telegram:poll', ['--once' => true])->assertExitCode(0);

        $this->assertDatabaseCount('cache_locks', 0);
        $this->assertSame(0, TelegramBotState::query()->findOrFail(12345)->next_update_id);
        Http::assertSentCount(3);
    }

    public function test_persistent_offset_prevents_replaying_old_unsubscribe_commands(): void
    {
        $user = $this->user();
        TelegramConnection::factory()->connected()->create(['recipient_id' => $user->id]);
        TelegramBotState::factory()->create(['bot_id' => 12345, 'next_update_id' => 50]);
        $this->fakePolling([['update_id' => 49, 'message' => ['chat' => ['id' => 123456, 'type' => 'private'], 'from' => ['id' => 123456, 'is_bot' => false], 'text' => '/stop']]]);

        $this->artisan('telegram:poll', ['--once' => true])->assertExitCode(0);

        $this->assertTrue(app(TelegramConnectionService::class)->status($user)['connected']);
        $this->assertSame(50, TelegramBotState::query()->findOrFail(12345)->next_update_id);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/getUpdates') && $request['offset'] === 50);
    }

    public function test_existing_webhook_aborts_polling_without_deleting_or_replacing_it(): void
    {
        $this->fakePolling([], 'https://other-service.example/webhook');

        $this->artisan('telegram:poll', ['--once' => true])->expectsOutputToContain('existing webhook')->assertExitCode(1);

        Http::assertSentCount(2);
        $this->assertDatabaseCount('telegram_bot_states', 0);
    }

    public function test_single_polling_lock_blocks_a_second_process_before_network_requests(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/bot12345:test_token/getMe' => Http::response(['ok' => true, 'result' => []])]);
        $lock = Cache::lock('hris-telegram-poll-12345', 90);
        $this->assertTrue($lock->get());
        try {
            $this->artisan('telegram:poll', ['--once' => true])->expectsOutputToContain('already running')->assertExitCode(1);
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
    }

    public function test_failed_batch_keeps_offset_and_reports_only_sanitized_error(): void
    {
        TelegramBotState::factory()->create(['bot_id' => 12345, 'next_update_id' => 20]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/bot12345:test_token/getMe' => Http::response(['ok' => true, 'result' => ['id' => 12345, 'username' => 'example_bot', 'is_bot' => true]]),
            'https://api.telegram.org/bot12345:test_token/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '']]),
            'https://api.telegram.org/bot12345:test_token/getUpdates' => Http::response(['ok' => false, 'error_code' => 500, 'description' => 'Private details or token']),
        ]);

        $this->artisan('telegram:poll', ['--once' => true])->expectsOutputToContain('Telegram request failed (api).')->assertExitCode(1);

        $this->assertSame(20, TelegramBotState::query()->findOrFail(12345)->next_update_id);
        Http::assertSentCount(3);
    }

    public function test_cursor_advanced_during_poll_prevents_stale_stop_and_backwards_offset(): void
    {
        $user = $this->user();
        TelegramConnection::factory()->connected()->create(['recipient_id' => $user->id]);
        TelegramBotState::factory()->create(['bot_id' => 12345, 'next_update_id' => 20]);
        $this->fakePollingRequest(function (): array {
            TelegramBotState::query()->whereKey(12345)->update(['next_update_id' => 50]);

            return [['update_id' => 20, 'message' => ['chat' => ['id' => 123456, 'type' => 'private'], 'from' => ['id' => 123456, 'is_bot' => false], 'text' => '/stop']]];
        });

        $this->artisan('telegram:poll', ['--once' => true])->assertExitCode(0);

        $this->assertTrue(app(TelegramConnectionService::class)->status($user)['connected']);
        $this->assertSame(50, TelegramBotState::query()->findOrFail(12345)->next_update_id);
        Http::assertSentCount(3);
    }

    public function test_lost_lease_after_poll_prevents_applying_the_returned_batch(): void
    {
        $user = $this->user();
        TelegramConnection::factory()->connected()->create(['recipient_id' => $user->id]);
        TelegramBotState::factory()->create(['bot_id' => 12345, 'next_update_id' => 20]);
        $this->fakePollingRequest(function (): array {
            Cache::lock('hris-telegram-poll-12345', 90)->forceRelease();

            return [['update_id' => 20, 'message' => ['chat' => ['id' => 123456, 'type' => 'private'], 'from' => ['id' => 123456, 'is_bot' => false], 'text' => '/stop']]];
        });

        $this->artisan('telegram:poll', ['--once' => true])->expectsOutputToContain('polling lock was lost')->assertExitCode(1);

        $this->assertTrue(app(TelegramConnectionService::class)->status($user)['connected']);
        $this->assertSame(20, TelegramBotState::query()->findOrFail(12345)->next_update_id);
        Http::assertSentCount(3);
    }

    #[DataProvider('temporaryPollFailures')]
    public function test_continuous_poll_retries_temporary_errors_and_keeps_committed_cursor(string $failure, array $expectedDelays): void
    {
        Sleep::fake(syncWithCarbon: true);
        Http::preventStrayRequests();
        TelegramBotState::factory()->create(['bot_id' => 12345, 'next_update_id' => 20]);
        $sequence = Http::sequence();
        if ($failure === 'server') {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $sequence->push(['ok' => false, 'error_code' => 503, 'description' => 'Private server response'], 503);
            }
        } elseif ($failure === 'connection') {
            $sequence->pushFailedConnection('Private connection details');
        } else {
            $sequence->push(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 47]], 429);
        }
        $sequence->push(['ok' => true, 'result' => [['update_id' => 20]]]);
        $sequence->push(['ok' => false, 'error_code' => 401], 401);
        Http::fake([
            'https://api.telegram.org/bot12345:test_token/getMe' => Http::response(['ok' => true, 'result' => ['id' => 12345, 'username' => 'example_bot', 'is_bot' => true]]),
            'https://api.telegram.org/bot12345:test_token/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '']]),
            'https://api.telegram.org/bot12345:test_token/getUpdates' => $sequence,
        ]);

        $this->artisan('telegram:poll')->expectsOutputToContain('Telegram request failed (api).')->assertExitCode(1);

        $this->assertSame(21, TelegramBotState::query()->findOrFail(12345)->next_update_id);
        Sleep::assertSequence(array_map(fn (int $delay): Sleep => Sleep::for($delay)->seconds(), $expectedDelays));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/getUpdates') && $request['timeout'] === 20 && $request['offset'] === 21);
    }

    public static function temporaryPollFailures(): array
    {
        return [
            'server backoff cap' => ['server', [5, 10, 20, 30, 10, 30, 30]],
            'connection timeout' => ['connection', [5]],
            'rate limit respects delay' => ['rate_limit', [30, 17]],
        ];
    }

    private function fakePollingRequest(\Closure $updates): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/bot12345:test_token/getMe' => Http::response(['ok' => true, 'result' => ['id' => 12345, 'username' => 'example_bot', 'is_bot' => true]]),
            'https://api.telegram.org/bot12345:test_token/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '']]),
            'https://api.telegram.org/bot12345:test_token/getUpdates' => fn () => Http::response(['ok' => true, 'result' => $updates()]),
        ]);
    }

    private function fakePolling(array $updates, string $webhookUrl = ''): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/bot12345:test_token/getMe' => Http::response(['ok' => true, 'result' => ['id' => 12345, 'username' => 'example_bot', 'is_bot' => true]]),
            'https://api.telegram.org/bot12345:test_token/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => $webhookUrl]]),
            'https://api.telegram.org/bot12345:test_token/getUpdates' => Http::response(['ok' => true, 'result' => $updates]),
        ]);
    }
}
