<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\TelegramBotState;
use App\Models\TelegramConnection;
use App\Services\Telegram\TelegramConnectionService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\FormRequestTestCase;

class TelegramBotCommandTest extends FormRequestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['telegram.enabled' => true, 'telegram.bot_token' => '12345:test_token', 'telegram.bot_username' => 'example_bot',
            'telegram.bot_id' => 12345, 'telegram.frontend_url' => 'https://hris.example']);
    }

    #[TestWith(['/start'])]
    #[TestWith(['/help'])]
    #[TestWith(['/HELP@EXAMPLE_BOT'])]
    public function test_start_and_help_show_commands_without_creating_an_account_link(string $command): void
    {
        $this->fakeApi();

        app(TelegramConnectionService::class)->handleUpdate($this->message($command));

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '777' && str_contains($request['text'], '/status -')
            && str_contains($request['text'], '/unlink -') && str_contains($request['text'], 'Connect Telegram')
            && ! isset($request['parse_mode']) && $request['link_preview_options'] === ['is_disabled' => true]);
        $this->assertDatabaseCount('telegram_connections', 0);
    }

    #[TestWith(['user', 'your employee account'])]
    #[TestWith(['applicant', 'your applicant account'])]
    public function test_status_identifies_only_the_connected_account_kind_without_personal_details(string $type, string $label): void
    {
        $actor = $type === 'user' ? $this->user() : Applicant::factory()->create();
        TelegramConnection::factory()->connected('777')->create(['recipient_type' => $type, 'recipient_id' => $actor->id]);
        $this->fakeApi();

        app(TelegramConnectionService::class)->handleUpdate($this->message('/status'));

        Http::assertSent(function (Request $request) use ($label, $actor): bool {
            return str_ends_with($request->url(), '/sendMessage') && $request['chat_id'] === '777'
                && str_contains($request['text'], $label) && ! str_contains($request['text'], $actor->email);
        });
    }

    public function test_status_can_report_both_account_kinds_for_the_same_chat(): void
    {
        TelegramConnection::factory()->connected('777')->create(['recipient_id' => $this->user()->id]);
        TelegramConnection::factory()->connected('777')->create(['recipient_type' => 'applicant', 'recipient_id' => Applicant::factory()->create()->id]);
        $this->fakeApi();

        app(TelegramConnectionService::class)->handleUpdate($this->message('/status@example_bot'));

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request['text'], 'your employee and applicant accounts'));
    }

    #[TestWith(['foreign chat'])]
    #[TestWith(['other bot'])]
    #[TestWith(['inactive account'])]
    #[TestWith(['unsubscribed'])]
    #[TestWith(['unlinked'])]
    public function test_status_does_not_report_ineligible_connections(string $condition): void
    {
        $actor = $this->user();
        $connection = TelegramConnection::factory()->connected('777')->create(['recipient_id' => $actor->id]);
        match ($condition) {
            'foreign chat' => $connection->update(['chat_id' => '888']),
            'other bot' => $connection->update(['bot_id' => 54321]),
            'inactive account' => $actor->update(['is_active' => false]),
            'unsubscribed' => $connection->update(['unsubscribed_at' => now()]),
            'unlinked' => $connection->update(['linked_at' => null]),
        };
        $this->fakeApi();

        app(TelegramConnectionService::class)->handleUpdate($this->message('/status'));

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request['text'], 'not connected to an active HRIS account'));
    }

    #[TestWith(['user', '/dashboard', '/applicant/applications'])]
    #[TestWith(['applicant', '/applicant/applications', '/dashboard'])]
    public function test_portal_offers_the_connected_account_portal(string $type, string $path, string $otherPath): void
    {
        $actor = $type === 'user' ? $this->user() : Applicant::factory()->create();
        TelegramConnection::factory()->connected('777')->create(['recipient_type' => $type, 'recipient_id' => $actor->id]);
        $this->fakeApi();

        app(TelegramConnectionService::class)->handleUpdate($this->message('/portal'));

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request['text'], 'https://hris.example'.$path) && ! str_contains($request['text'], $otherPath));
    }

    public function test_unlinked_chat_can_open_either_portal_without_bypassing_sign_in(): void
    {
        $this->fakeApi();

        app(TelegramConnectionService::class)->handleUpdate($this->message('/portal'));

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request['text'], 'sign in') && str_contains($request['text'], 'https://hris.example/dashboard')
            && str_contains($request['text'], 'https://hris.example/applicant/applications'));
        $this->assertDatabaseCount('telegram_connections', 0);
    }

    #[TestWith(['javascript:alert(1)'])]
    #[TestWith(['https://user:secret@hris.example'])]
    #[TestWith(['https://hris.example?token=private'])]
    #[TestWith(['https://hris.example#private'])]
    public function test_portal_does_not_send_unsafe_or_credential_bearing_urls(string $url): void
    {
        config(['telegram.frontend_url' => $url]);
        $this->fakeApi();

        app(TelegramConnectionService::class)->handleUpdate($this->message('/portal'));

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request['text'], 'currently unavailable') && ! str_contains($request['text'], $url));
    }

    #[TestWith(['/unlink'])]
    #[TestWith(['/stop@example_bot'])]
    public function test_unsubscribe_disconnects_both_account_kinds_only_for_this_chat_and_bot(string $command): void
    {
        $own = TelegramConnection::factory()->connected('777')->create(['link_token_hash' => hash('sha256', 'pending'), 'link_token_expires_at' => now()->addMinutes(10)]);
        $applicant = TelegramConnection::factory()->connected('777')->create(['recipient_type' => 'applicant', 'recipient_id' => Applicant::factory()->create()->id]);
        $foreign = TelegramConnection::factory()->connected('888')->create();
        $otherBot = TelegramConnection::factory()->connected('777')->create(['bot_id' => 54321]);
        $this->fakeApi();

        app(TelegramConnectionService::class)->handleUpdate($this->message($command));

        foreach ([$own, $applicant] as $connection) {
            $fresh = $connection->fresh();
            $this->assertNull($fresh->chat_id);
            $this->assertNull($fresh->linked_at);
            $this->assertNull($fresh->link_token_hash);
            $this->assertNotNull($fresh->unsubscribed_at);
            $this->assertNotSame($connection->connection_key, $fresh->connection_key);
        }
        $this->assertSame('888', $foreign->fresh()->chat_id);
        $this->assertSame('777', $otherBot->fresh()->chat_id);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request['text'], 'notifications have been stopped'));
    }

    #[DataProvider('unsafeMessages')]
    public function test_unsafe_commands_are_ignored_without_reply_or_disconnect(array $changes): void
    {
        $connection = TelegramConnection::factory()->connected('777')->create();
        $this->fakeApi();
        $update = $this->message('/unlink');
        $update['message'] = array_replace_recursive($update['message'], $changes);

        app(TelegramConnectionService::class)->handleUpdate($update);

        $this->assertSame('777', $connection->fresh()->chat_id);
        $this->assertSame($connection->connection_key, $connection->fresh()->connection_key);
        Http::assertNothingSent();
    }

    public static function unsafeMessages(): array
    {
        return [
            'group' => [['chat' => ['type' => 'group', 'id' => -777]]],
            'foreign sender' => [['from' => ['id' => 888]]],
            'bot sender' => [['from' => ['is_bot' => true]]],
            'forward' => [['forward_origin' => ['type' => 'user']]],
            'legacy forward' => [['forward_from' => ['id' => 777]]],
            'other bot suffix' => [['text' => '/unlink@another_bot']],
            'extra arguments' => [['text' => '/unlink 888']],
            'unknown command' => [['text' => '/employee 1']],
            'free text' => [['text' => 'show my profile']],
        ];
    }

    public function test_rollback_discards_unlink_and_its_reply(): void
    {
        $connection = TelegramConnection::factory()->connected('777')->create();
        $this->fakeApi();

        DB::beginTransaction();
        app(TelegramConnectionService::class)->handleUpdate($this->message('/unlink'));
        DB::rollBack();

        $this->assertSame('777', $connection->fresh()->chat_id);
        Http::assertNothingSent();
    }

    public function test_polling_commits_unlink_and_cursor_even_when_reply_delivery_fails(): void
    {
        $connection = TelegramConnection::factory()->connected('777')->create();
        $this->fakeApi([$this->message('/unlink', 42)], false);
        Log::shouldReceive('warning')->once()->with('Telegram command reply could not be delivered.', ['category' => 'api', 'error_code' => 503]);

        $this->artisan('telegram:poll', ['--once' => true])->assertExitCode(0);

        $this->assertNull($connection->fresh()->chat_id);
        $this->assertNotNull($connection->fresh()->unsubscribed_at);
        $this->assertSame(43, TelegramBotState::query()->findOrFail(12345)->next_update_id);
        Http::assertSentCount(4);
    }

    public function test_polling_replies_after_the_cursor_commit_and_does_not_replay_duplicate_commands(): void
    {
        $this->fakeApi([$this->message('/help', 42), $this->message('/help', 42)], replyHandler: function () {
            $this->assertSame(43, TelegramBotState::query()->findOrFail(12345)->next_update_id);

            return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
        });

        $this->artisan('telegram:poll', ['--once' => true])->assertExitCode(0);

        Http::assertSentCount(4);
    }

    private function message(string $command, int $updateId = 1): array
    {
        return ['update_id' => $updateId, 'message' => ['chat' => ['id' => 777, 'type' => 'private'],
            'from' => ['id' => 777, 'is_bot' => false], 'text' => $command]];
    }

    private function fakeApi(array $updates = [], bool $replySucceeds = true, ?callable $replyHandler = null): void
    {
        Http::fake([
            'https://api.telegram.org/bot12345:test_token/getMe' => Http::response(['ok' => true, 'result' => ['id' => 12345, 'username' => 'example_bot', 'is_bot' => true]]),
            'https://api.telegram.org/bot12345:test_token/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '']]),
            'https://api.telegram.org/bot12345:test_token/getUpdates' => Http::response(['ok' => true, 'result' => $updates]),
            'https://api.telegram.org/bot12345:test_token/sendMessage' => $replyHandler ?? ($replySucceeds
                ? Http::response(['ok' => true, 'result' => ['message_id' => 1]])
                : Http::response(['ok' => false, 'error_code' => 503, 'description' => 'Private transport details'], 503)),
        ]);
    }
}
