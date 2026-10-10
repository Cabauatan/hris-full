<?php

namespace Tests\Feature;

use App\Events\TelegramConnectionUpdated;
use App\Models\Applicant;
use App\Models\TelegramConnection;
use App\Models\TelegramDelivery;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Notification\TelegramNotificationService;
use App\Services\Telegram\TelegramConnectionService;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\FormRequestTestCase;

class TelegramConnectionRealtimeTest extends FormRequestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['telegram.enabled' => true, 'telegram.bot_token' => '12345:test_token', 'telegram.bot_username' => 'example_bot', 'telegram.bot_id' => 12345]);
    }

    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_committed_link_queues_one_empty_private_update_and_replay_does_not_repeat_it(string $realm): void
    {
        $this->freezeTime();
        $actor = $this->actor($realm);
        $service = app(TelegramConnectionService::class);
        $code = $this->code($service->createLink($actor)['url']);
        $this->enableRealtime();
        Queue::fake([BroadcastEvent::class]);
        DB::beginTransaction();

        $service->handleUpdate($this->message('/start '.$code));
        Queue::assertNothingPushed();
        DB::commit();
        $service->handleUpdate($this->message('/start '.$code));

        $this->assertTrue($service->status($actor)['connected']);
        Queue::assertPushedOn('notifications', BroadcastEvent::class, function (BroadcastEvent $job) use ($realm, $actor): bool {
            $this->assertSame('telegram.connection.updated', $job->event->broadcastAs());
            $this->assertSame([], $job->event->broadcastWith());
            $this->assertSame('private-App.Models.'.($realm === 'web' ? 'User' : 'Applicant').'.'.$actor->id, (string) $job->event->broadcastOn()[0]);

            return true;
        });
        Queue::assertPushed(BroadcastEvent::class, 1);
        Http::assertNothingSent();
    }

    public function test_rolled_back_link_does_not_emit_an_update(): void
    {
        $actor = $this->user();
        $service = app(TelegramConnectionService::class);
        $code = $this->code($service->createLink($actor)['url']);
        $this->enableRealtime();
        Event::fake([TelegramConnectionUpdated::class]);
        DB::beginTransaction();

        $service->handleUpdate($this->message('/start '.$code));
        DB::rollBack();

        $this->assertFalse($service->status($actor)['connected']);
        Event::assertNotDispatched(TelegramConnectionUpdated::class);
        $this->assertDatabaseHas('telegram_connections', ['recipient_id' => $actor->id, 'link_token_hash' => hash('sha256', $code)]);
    }

    #[TestWith(['expired'])]
    #[TestWith(['inactive'])]
    #[TestWith(['group'])]
    #[TestWith(['disabled_broadcast'])]
    public function test_invalid_link_or_disabled_broadcast_does_not_emit_an_update(string $case): void
    {
        $this->freezeTime();
        $actor = $this->user();
        $service = app(TelegramConnectionService::class);
        $code = $this->code($service->createLink($actor)['url']);
        $this->enableRealtime();
        Event::fake([TelegramConnectionUpdated::class]);
        $update = $this->message('/start '.$code);
        if ($case === 'expired') {
            $this->travel(10)->minutes();
        } elseif ($case === 'inactive') {
            $actor->update(['is_active' => false]);
        } elseif ($case === 'group') {
            $update['message']['chat']['type'] = 'group';
        } else {
            config(['broadcasting.default' => 'null']);
        }

        $service->handleUpdate($update);

        Event::assertNotDispatched(TelegramConnectionUpdated::class);
        $this->assertSame($case === 'disabled_broadcast' ? '777' : null, TelegramConnection::query()->sole()->chat_id);
    }

    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_api_unlink_emits_only_the_owners_update(string $realm): void
    {
        $actor = $this->actor($realm);
        TelegramConnection::factory()->connected()->create(['recipient_type' => $realm === 'web' ? 'user' : 'applicant', 'recipient_id' => $actor->id]);
        $this->enableRealtime();
        Event::fake([TelegramConnectionUpdated::class]);

        $this->actingAs($actor, $realm)->deleteJson('/api/v1/'.($realm === 'applicant' ? 'applicant/' : '').'auth/telegram')->assertNoContent();

        $this->assertNull(TelegramConnection::query()->sole()->chat_id);
        Event::assertDispatchedTimes(TelegramConnectionUpdated::class, 1);
        Event::assertDispatched(TelegramConnectionUpdated::class, fn (TelegramConnectionUpdated $event): bool => $event->recipientId === $actor->id && $event->recipientType === ($realm === 'web' ? 'user' : 'applicant'));
    }

    #[TestWith(['stop'])]
    #[TestWith(['unlink'])]
    #[TestWith(['blocked'])]
    public function test_chat_disconnect_emits_separate_updates_for_both_account_realms(string $reason): void
    {
        $user = $this->user();
        $applicant = Applicant::factory()->create(['id' => $user->id]);
        foreach (['user', 'applicant'] as $type) {
            TelegramConnection::factory()->connected('777')->create(['recipient_type' => $type, 'recipient_id' => $user->id]);
        }
        Http::fake([
            'https://api.telegram.org/bot12345:test_token/getMe' => Http::response(['ok' => true, 'result' => ['id' => 12345, 'username' => 'example_bot', 'is_bot' => true]]),
            'https://api.telegram.org/bot12345:test_token/sendMessage' => $reason === 'blocked'
                ? Http::response(['ok' => false, 'error_code' => 403], 403)
                : Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
        $delivery = null;
        if ($reason === 'blocked') {
            config(['queue.default' => 'database']);
            app(TelegramNotificationService::class)->queue('user', $user->id, 'password_changed', $user);
            $delivery = TelegramDelivery::query()->sole();
        }
        $this->enableRealtime();
        Event::fake([TelegramConnectionUpdated::class]);

        if ($delivery !== null) {
            app(TelegramNotificationService::class)->deliver($delivery->id);
        } else {
            app(TelegramConnectionService::class)->handleUpdate($this->message('/'.$reason));
        }

        $this->assertSame(2, TelegramConnection::query()->whereNull('chat_id')->count());
        Event::assertDispatchedTimes(TelegramConnectionUpdated::class, 2);
        foreach (['user', 'applicant'] as $type) {
            Event::assertDispatched(TelegramConnectionUpdated::class, fn (TelegramConnectionUpdated $event): bool => $event->recipientType === $type && $event->recipientId === $applicant->id);
        }
        Http::assertSentCount(2);
    }

    public function test_applicant_can_sign_only_its_own_channel_with_both_account_realms_logged_in(): void
    {
        $user = $this->user();
        $applicant = Applicant::factory()->create(['id' => $user->id]);
        $other = Applicant::factory()->create();
        $this->enableRealtime();
        $this->actingAs($user, 'web')->actingAs($applicant, 'applicant');

        $response = $this->postJson('/api/v1/applicant/broadcasting/auth', $this->payload($applicant->id))->assertOk()->assertJsonStructure(['auth']);
        $this->assertStringStartsWith('telegram-public:', $response->json('auth'));
        $this->assertStringNotContainsString('telegram-secret', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->postJson('/api/v1/applicant/broadcasting/auth', $this->payload($other->id))->assertForbidden()->assertJsonMissingPath('auth');
        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-App.Models.User.'.$user->id])->assertOk();
    }

    public function test_guest_employee_only_session_and_inactive_applicant_are_denied(): void
    {
        $this->postJson('/api/v1/applicant/broadcasting/auth', $this->payload(1))->assertUnauthorized();
        $this->actingAs($this->user(), 'web')->postJson('/api/v1/applicant/broadcasting/auth', $this->payload(1))->assertUnauthorized();
        $applicant = Applicant::factory()->create(['is_active' => false]);
        $this->actingAs($applicant, 'applicant')->postJson('/api/v1/applicant/broadcasting/auth', $this->payload($applicant->id))->assertForbidden();
    }

    #[TestWith(['private-App.Models.User.1', '123.456', 'channel_name'])]
    #[TestWith(['private-App.Models.Applicant.01', '123.456', 'channel_name'])]
    #[TestWith(['private-Recruitment.Interview.test', '123.456', 'channel_name'])]
    #[TestWith(['private-App.Models.Applicant.1', 'bad', 'socket_id'])]
    public function test_applicant_auth_refuses_foreign_namespaces_noncanonical_ids_and_invalid_sockets(string $channel, string $socket, string $field): void
    {
        $this->actingAs(Applicant::factory()->create(), 'applicant')->postJson('/api/v1/applicant/broadcasting/auth', ['socket_id' => $socket, 'channel_name' => $channel])
            ->assertUnprocessable()->assertJsonValidationErrors($field)->assertJsonMissingPath('auth');
    }

    public function test_applicant_auth_requires_a_real_csrf_token(): void
    {
        $applicant = Applicant::factory()->create();
        $this->actingAs($applicant, 'applicant');
        $this->app->instance('env', 'local');
        try {
            $this->postJson('/api/v1/applicant/broadcasting/auth', $this->payload($applicant->id))->assertStatus(419);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    private function enableRealtime(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'telegram-public',
            'broadcasting.connections.reverb.secret' => 'telegram-secret', 'broadcasting.connections.reverb.app_id' => 'telegram-fixture']);
        $this->app->getProvider(AppServiceProvider::class)->boot();
    }

    private function actor(string $realm): User|Applicant
    {
        return $realm === 'web' ? $this->user() : Applicant::factory()->create();
    }

    private function payload(int $id): array
    {
        return ['socket_id' => '123.456', 'channel_name' => 'private-App.Models.Applicant.'.$id];
    }

    private function code(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['start'];
    }

    private function message(string $text): array
    {
        return ['update_id' => 1, 'message' => ['chat' => ['id' => 777, 'type' => 'private'], 'from' => ['id' => 777, 'is_bot' => false], 'text' => $text]];
    }
}
