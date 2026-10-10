<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\TelegramConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\FormRequestTestCase;

class TelegramConnectionApiTest extends FormRequestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['telegram.enabled' => true, 'telegram.bot_id' => 12345,
            'telegram.bot_username' => 'TestHrisBot', 'telegram.bot_token' => '12345:fake-test-token']);
    }

    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_status_and_link_expose_only_safe_self_service_fields(string $realm): void
    {
        $this->travelTo('2026-10-08 10:00:00');
        $actor = $this->actor($realm);
        $url = $this->url($realm);
        $this->actingAs($actor, $realm)->getJson($url)->assertOk()->assertExactJson(['data' => [
            'configured' => true, 'bot_username' => 'TestHrisBot', 'connected' => false, 'linked_at' => null,
        ]]);
        $this->assertDatabaseCount('telegram_connections', 0);

        $response = $this->postJson($url.'/link')->assertCreated()->assertJsonStructure(['data' => ['url', 'expires_at']]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $data = $response->json('data');
        $this->assertSame(['url', 'expires_at'], array_keys($data));
        $this->assertMatchesRegularExpression('~^https://t\.me/TestHrisBot\?start=[A-Za-z0-9_-]{43}$~D', $data['url']);
        $nonce = substr($data['url'], strpos($data['url'], '?start=') + 7);
        $connection = TelegramConnection::query()->sole();
        $this->assertSame($realm === 'web' ? 'user' : 'applicant', $connection->recipient_type);
        $this->assertSame($actor->id, $connection->recipient_id);
        $this->assertSame(hash('sha256', $nonce), $connection->link_token_hash);
        $this->assertSame(now()->addMinutes(10)->toIso8601String(), $data['expires_at']);
        $this->assertNull($connection->chat_id);
        $this->assertNull($connection->linked_at);
        $this->assertStringNotContainsString($nonce, $connection->toJson());

        $replacement = $this->postJson($url.'/link')->assertCreated()->json('data.url');
        $this->assertNotSame($data['url'], $replacement);
        $this->assertNotSame(hash('sha256', $nonce), $connection->fresh()->link_token_hash);
        Http::assertNothingSent();
    }

    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_guest_wrong_realm_and_inactive_accounts_are_refused(string $realm): void
    {
        $url = $this->url($realm);
        foreach ([['GET', $url], ['POST', $url.'/link'], ['DELETE', $url]] as [$method, $path]) {
            $this->json($method, $path)->assertUnauthorized();
        }
        $opposite = $realm === 'web' ? 'applicant' : 'web';
        $this->actingAs($this->actor($opposite), $opposite)->getJson($url)->assertUnauthorized();
        $actor = $this->actor($realm);
        $actor->update(['is_active' => false]);
        $this->actingAs($actor, $realm)->postJson($url.'/link')->assertForbidden();
        $this->assertDatabaseCount('telegram_connections', 0);
    }

    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_client_selected_chat_is_rejected_without_creating_a_connection(string $realm): void
    {
        $this->actingAs($this->actor($realm), $realm)->postJson($this->url($realm).'/link', ['chat_id' => '987654'])
            ->assertUnprocessable()->assertJsonValidationErrors('chat_id')
            ->assertJsonPath('errors.chat_id.0', 'The chat id field is prohibited.');
        $this->assertDatabaseCount('telegram_connections', 0);
    }

    public function test_explicit_guard_separates_matching_numeric_ids_and_unlinks_only_owner(): void
    {
        $user = $this->user();
        $applicant = Applicant::factory()->create(['id' => $user->id]);
        $employeeConnection = TelegramConnection::factory()->connected('11111')->create(['recipient_id' => $user->id]);
        $applicantConnection = TelegramConnection::factory()->connected('22222')->create(['recipient_type' => 'applicant', 'recipient_id' => $applicant->id]);
        $foreign = TelegramConnection::factory()->connected('33333')->create();
        $this->actingAs($user, 'web')->actingAs($applicant, 'applicant');
        foreach (['web', 'applicant'] as $realm) {
            $response = $this->getJson($this->url($realm))->assertOk()->assertExactJson(['data' => [
                'configured' => true, 'bot_username' => 'TestHrisBot', 'connected' => true, 'linked_at' => $employeeConnection->linked_at->toIso8601String(),
            ]]);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringNotContainsString('chat_id', $response->getContent());
        }
        $this->deleteJson($this->url('applicant'))->assertNoContent();
        $this->assertNull($applicantConnection->fresh()->chat_id);
        $this->assertNotNull($applicantConnection->fresh()->unsubscribed_at);
        $this->assertNotSame($applicantConnection->connection_key, $applicantConnection->fresh()->connection_key);
        $this->assertSame('11111', $employeeConnection->fresh()->chat_id);
        $this->assertSame('33333', $foreign->fresh()->chat_id);
    }

    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_disabled_channel_returns_safe_status_and_refuses_new_links(string $realm): void
    {
        config(['telegram.enabled' => false]);
        $this->actingAs($this->actor($realm), $realm)->getJson($this->url($realm))->assertOk()->assertExactJson(['data' => [
            'configured' => false, 'bot_username' => null, 'connected' => false, 'linked_at' => null,
        ]]);
        $this->postJson($this->url($realm).'/link')->assertStatus(503)
            ->assertJsonPath('message', 'Telegram notifications are not configured.');
        $this->assertDatabaseCount('telegram_connections', 0);
        Http::assertNothingSent();
    }

    public function test_link_limit_is_per_realm_account_and_resets_after_one_minute(): void
    {
        $this->travelTo('2026-10-08 10:00:00');
        $user = $this->user();
        $applicant = Applicant::factory()->create(['id' => $user->id]);
        $this->actingAs($user, 'web')->actingAs($applicant, 'applicant');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson($this->url('web').'/link')->assertCreated();
        }
        $hash = TelegramConnection::query()->where('recipient_type', 'user')->sole()->link_token_hash;
        $this->postJson($this->url('web').'/link')->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertSame($hash, TelegramConnection::query()->where('recipient_type', 'user')->sole()->link_token_hash);
        $this->postJson($this->url('applicant').'/link')->assertCreated();
        $this->travel(61)->seconds();
        $this->postJson($this->url('web').'/link')->assertCreated();
    }

    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_real_csrf_middleware_protects_connect_and_unlink(string $realm): void
    {
        $actor = $this->actor($realm);
        $this->actingAs($actor, $realm);
        $this->app->instance('env', 'local');
        try {
            $this->postJson($this->url($realm).'/link')->assertStatus(419);
            $this->deleteJson($this->url($realm))->assertStatus(419);
            $this->assertDatabaseCount('telegram_connections', 0);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    private function actor(string $realm): User|Applicant
    {
        return $realm === 'web' ? $this->user() : Applicant::factory()->create();
    }

    private function url(string $realm): string
    {
        return '/api/v1/'.($realm === 'applicant' ? 'applicant/' : '').'auth/telegram';
    }
}
