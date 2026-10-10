<?php

namespace Tests\Feature;

use App\Notifications\HrisNotification;
use App\Services\Notification\IdempotentDatabaseChannel;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class RealtimeAuthorizationTest extends FormRequestTestCase
{
    public function test_guest_cannot_authorize_a_private_channel(): void
    {
        $this->postJson('/api/v1/broadcasting/auth', $this->payload(1))->assertUnauthorized();
    }

    public function test_active_account_can_sign_only_its_own_channel_without_exposing_the_server_secret(): void
    {
        $actor = $this->user();
        $other = $this->user();
        $this->fixtureBroadcaster();
        $response = $this->actingAs($actor)->postJson('/api/v1/broadcasting/auth', $this->payload($actor->id))
            ->assertOk()->assertJsonStructure(['auth']);
        $this->assertStringStartsWith('phase15-public:', $response->json('auth'));
        $this->assertStringNotContainsString('phase15-private', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->postJson('/api/v1/broadcasting/auth', $this->payload($other->id))->assertForbidden();
    }

    public function test_inactive_account_is_denied_its_private_channel(): void
    {
        $actor = $this->user();
        $actor->update(['is_active' => false]);
        $this->actingAs($actor)->postJson('/api/v1/broadcasting/auth', $this->payload($actor->id))->assertForbidden();
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_socket_or_channel_is_422_without_generating_a_signature(array $payload, string $field): void
    {
        $this->actingAs($this->user())->postJson('/api/v1/broadcasting/auth', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field)->assertJsonMissingPath('auth');
    }

    public function test_new_database_notification_queues_one_private_ping_without_record_details_and_replay_does_not_repeat_it(): void
    {
        $this->freezeTime();
        $actor = $this->user();
        $this->fixtureBroadcaster();
        Event::fake([BroadcastNotificationCreated::class]);
        $notification = new HrisNotification(['resource_type' => 'cto', 'resource_id' => 123, 'event' => 'approved', 'occurred_at' => now()->toIso8601String()]);
        $notification->id = (string) Str::uuid();
        $channel = app(IdempotentDatabaseChannel::class);
        $first = $channel->send($actor, $notification);
        $second = $channel->send($actor, $notification);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $actor->notifications()->count());
        Event::assertDispatchedTimes(BroadcastNotificationCreated::class, 1);
        Event::assertDispatched(BroadcastNotificationCreated::class, function (BroadcastNotificationCreated $event) use ($actor, $notification): bool {
            $this->assertSame($actor->id, $event->notifiable->id);
            $this->assertSame('notifications', $event->queue);
            $this->assertSame([], $event->data);
            $this->assertSame(['id' => $notification->id, 'type' => HrisNotification::class], $event->broadcastWith());
            $this->assertSame('private-App.Models.User.'.$actor->id, (string) $event->broadcastOn()[0]);

            return true;
        });
    }

    public function test_disabled_broadcasting_keeps_database_notifications_available_without_dispatching_a_ping(): void
    {
        $this->freezeTime();
        $actor = $this->user();
        Event::fake([BroadcastNotificationCreated::class]);
        $notification = new HrisNotification(['resource_type' => 'cto', 'resource_id' => 123, 'event' => 'approved', 'occurred_at' => now()->toIso8601String()]);
        $notification->id = (string) Str::uuid();
        app(IdempotentDatabaseChannel::class)->send($actor, $notification);

        $this->assertSame(1, $actor->notifications()->count());
        Event::assertNotDispatched(BroadcastNotificationCreated::class);
    }

    public static function invalidPayloads(): array
    {
        return [
            'array socket' => [['socket_id' => ['1.2'], 'channel_name' => 'private-App.Models.User.1'], 'socket_id'],
            'malformed socket' => [['socket_id' => 'invalid', 'channel_name' => 'private-App.Models.User.1'], 'socket_id'],
            'unsupported channel' => [['socket_id' => '1.2', 'channel_name' => 'private-workspace.staff'], 'channel_name'],
            'noncanonical account' => [['socket_id' => '1.2', 'channel_name' => 'private-App.Models.User.01'], 'channel_name'],
        ];
    }

    private function fixtureBroadcaster(): void
    {
        $channels = Broadcast::getChannels();
        config([
            'broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'phase15-public',
            'broadcasting.connections.reverb.secret' => 'phase15-private', 'broadcasting.connections.reverb.app_id' => 'phase15-fixture',
        ]);
        foreach ($channels as $name => $callback) {
            Broadcast::channel($name, $callback);
        }
    }

    private function payload(int $userId): array
    {
        return ['socket_id' => '123.456', 'channel_name' => 'private-App.Models.User.'.$userId];
    }
}
