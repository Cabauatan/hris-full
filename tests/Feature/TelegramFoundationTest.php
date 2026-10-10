<?php

namespace Tests\Feature;

use App\Models\TelegramBotState;
use App\Models\TelegramConnection;
use App\Models\TelegramDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\FormRequestTestCase;

class TelegramFoundationTest extends FormRequestTestCase
{
    public function test_factories_cast_dates_and_keep_private_connection_details_hidden(): void
    {
        $connection = TelegramConnection::factory()->connected()->create();
        $delivery = TelegramDelivery::factory()->create(['recipient_id' => $connection->recipient_id, 'connection_key' => $connection->connection_key]);
        $state = TelegramBotState::factory()->create();

        $this->assertNotNull($connection->fresh()->linked_at);
        $this->assertSame($connection->recipient_id, $delivery->fresh()->resource_id);
        $this->assertArrayNotHasKey('chat_id', $connection->toArray());
        $this->assertArrayNotHasKey('link_token_hash', $connection->toArray());
        $this->assertArrayNotHasKey('connection_key', $delivery->toArray());
        $this->assertSame(0, $state->fresh()->next_update_id);
    }

    public function test_operational_tables_store_consent_safe_delivery_ids_and_poll_cursor(): void
    {
        $user = $this->user();
        $key = (string) Str::uuid();
        DB::table('telegram_connections')->insert(['recipient_type' => 'user', 'recipient_id' => $user->id, 'connection_key' => $key]);
        DB::table('telegram_deliveries')->insert([
            'id' => (string) Str::uuid(), 'resource_type' => 'user', 'resource_id' => $user->id,
            'event' => 'password_changed', 'event_id' => (string) Str::uuid(), 'recipient_type' => 'user',
            'recipient_id' => $user->id, 'connection_key' => $key, 'occurred_at' => now(),
        ]);
        DB::table('telegram_bot_states')->insert(['bot_id' => 12345]);

        $connection = (array) DB::table('telegram_connections')->first();
        $delivery = (array) DB::table('telegram_deliveries')->first();
        $this->assertNull($connection['chat_id']);
        $this->assertNull($connection['link_token_hash']);
        $this->assertSame('queued', $delivery['status']);
        $this->assertNull($delivery['sent_at']);
        $this->assertArrayNotHasKey('chat_id', $delivery);
        $this->assertArrayNotHasKey('text', $delivery);
        $this->assertArrayNotHasKey('token', $delivery);
        $this->assertSame(0, DB::table('telegram_bot_states')->value('next_update_id'));
    }
}
