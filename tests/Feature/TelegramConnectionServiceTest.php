<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\TelegramConnection;
use App\Services\Telegram\TelegramConnectionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class TelegramConnectionServiceTest extends FormRequestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['telegram.enabled' => true, 'telegram.bot_token' => '12345:test_token', 'telegram.bot_username' => 'example_bot', 'telegram.bot_id' => 12345]);
    }

    public function test_link_code_is_hashed_expiring_and_regeneration_preserves_current_consent(): void
    {
        $this->freezeTime();
        $user = $this->user();
        $connection = TelegramConnection::factory()->connected()->create(['recipient_id' => $user->id]);
        $key = $connection->connection_key;
        $service = app(TelegramConnectionService::class);
        $first = $service->createLink($user);
        $second = $service->createLink($user);
        $code = $this->code($second['url']);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $code);
        $this->assertNotSame($first['url'], $second['url']);
        $this->assertSame(hash('sha256', $code), $connection->fresh()->link_token_hash);
        $this->assertSame(now()->addMinutes(10)->toIso8601String(), $second['expires_at']);
        $this->assertSame($key, $connection->fresh()->connection_key);
        $this->assertTrue($service->status($user)['connected']);
        $this->assertStringNotContainsString($code, json_encode($connection->fresh()->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_private_start_consumes_code_once_and_rotates_consent_identity(): void
    {
        $user = $this->user();
        $service = app(TelegramConnectionService::class);
        $code = $this->code($service->createLink($user)['url']);
        $pendingKey = TelegramConnection::query()->firstOrFail()->connection_key;
        $service->handleUpdate($this->message('/start '.$code));
        $connection = TelegramConnection::query()->firstOrFail();
        $linkedKey = $connection->connection_key;
        $service->handleUpdate($this->message('/start '.$code, 888));

        $this->assertSame('777', $connection->fresh()->chat_id);
        $this->assertSame(12345, $connection->fresh()->bot_id);
        $this->assertNull($connection->fresh()->link_token_hash);
        $this->assertNull($connection->fresh()->link_token_expires_at);
        $this->assertNotSame($pendingKey, $linkedKey);
        $this->assertSame($linkedKey, $connection->fresh()->connection_key);
        $this->assertTrue($service->status($user)['connected']);
    }

    #[DataProvider('unsafeMessages')]
    public function test_unsafe_start_messages_do_not_consume_link(array $changes): void
    {
        $user = $this->user();
        $service = app(TelegramConnectionService::class);
        $code = $this->code($service->createLink($user)['url']);
        $update = $this->message('/start '.$code);
        $update['message'] = array_replace_recursive($update['message'], $changes);

        $service->handleUpdate($update);

        $this->assertFalse($service->status($user)['connected']);
        $this->assertDatabaseHas('telegram_connections', ['recipient_id' => $user->id, 'link_token_hash' => hash('sha256', $code), 'chat_id' => null]);
    }

    public static function unsafeMessages(): array
    {
        return [
            'group' => [['chat' => ['type' => 'group', 'id' => -777]]],
            'different sender' => [['from' => ['id' => 888]]],
            'bot sender' => [['from' => ['is_bot' => true]]],
            'forward' => [['forward_origin' => ['type' => 'user']]],
        ];
    }

    public function test_expired_and_replaced_codes_cannot_link(): void
    {
        $this->freezeTime();
        $user = $this->user();
        $service = app(TelegramConnectionService::class);
        $first = $this->code($service->createLink($user)['url']);
        $second = $this->code($service->createLink($user)['url']);
        $service->handleUpdate($this->message('/start '.$first));
        $this->travel(10)->minutes();
        $service->handleUpdate($this->message('/start '.$second));

        $this->assertFalse($service->status($user)['connected']);
        $this->assertNull(TelegramConnection::query()->firstOrFail()->chat_id);
    }

    public function test_inactive_account_cannot_generate_or_consume_a_link(): void
    {
        $user = $this->user();
        $service = app(TelegramConnectionService::class);
        $code = $this->code($service->createLink($user)['url']);
        $user->update(['is_active' => false]);
        $service->handleUpdate($this->message('/start '.$code));
        $this->assertNull(TelegramConnection::query()->firstOrFail()->chat_id);
        $this->assertNull(TelegramConnection::query()->firstOrFail()->link_token_hash);

        $this->expectException(AuthorizationException::class);
        $service->createLink($user);
    }

    public function test_same_chat_can_link_both_account_kinds_and_stop_clears_both(): void
    {
        $user = $this->user();
        $applicant = Applicant::factory()->create(['id' => $user->id]);
        $service = app(TelegramConnectionService::class);
        foreach ([$user, $applicant] as $actor) {
            $code = $this->code($service->createLink($actor)['url']);
            $service->handleUpdate($this->message('/start@example_bot '.$code));
        }
        $this->assertTrue($service->status($user)['connected']);
        $this->assertTrue($service->status($applicant)['connected']);

        Http::fake([
            'https://api.telegram.org/bot12345:test_token/getMe' => Http::response(['ok' => true, 'result' => ['id' => 12345, 'username' => 'example_bot', 'is_bot' => true]]),
            'https://api.telegram.org/bot12345:test_token/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        $service->handleUpdate($this->message('/stop'));

        $this->assertFalse($service->status($user)['connected']);
        $this->assertFalse($service->status($applicant)['connected']);
        $this->assertSame(2, TelegramConnection::query()->whereNull('chat_id')->whereNotNull('unsubscribed_at')->count());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage') && $request['chat_id'] === '777');
    }

    public function test_api_unlink_clears_only_the_actors_connection_and_pending_code(): void
    {
        $user = $this->user();
        $other = $this->user();
        $connection = TelegramConnection::factory()->connected()->create(['recipient_id' => $user->id, 'link_token_hash' => hash('sha256', 'pending'), 'link_token_expires_at' => now()->addMinutes(10)]);
        TelegramConnection::factory()->connected()->create(['recipient_id' => $other->id]);
        $service = app(TelegramConnectionService::class);

        $service->unlink($user);

        $this->assertFalse($service->status($user)['connected']);
        $this->assertTrue($service->status($other)['connected']);
        $this->assertNull($connection->fresh()->link_token_hash);
        $this->assertNotSame($connection->connection_key, $connection->fresh()->connection_key);
    }

    public function test_bot_identity_change_makes_old_consent_inactive(): void
    {
        $user = $this->user();
        TelegramConnection::factory()->connected()->create(['recipient_id' => $user->id]);
        config(['telegram.bot_id' => 54321]);

        $this->assertFalse(app(TelegramConnectionService::class)->status($user)['connected']);
    }

    private function code(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['start'];
    }

    private function message(string $text, int $chatId = 777): array
    {
        return ['update_id' => 1, 'message' => ['chat' => ['id' => $chatId, 'type' => 'private'], 'from' => ['id' => $chatId, 'is_bot' => false], 'text' => $text]];
    }
}
