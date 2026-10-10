<?php

namespace Database\Factories;

use App\Models\TelegramConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TelegramConnection>
 */
class TelegramConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipient_type' => 'user',
            'recipient_id' => User::factory()->state(['uuid' => (string) Str::uuid(), 'is_active' => true]),
            'chat_id' => null,
            'bot_id' => null,
            'connection_key' => (string) Str::uuid(),
            'link_token_hash' => null,
            'link_token_expires_at' => null,
            'linked_at' => null,
            'unsubscribed_at' => null,
        ];
    }

    public function connected(string $chatId = '123456'): static
    {
        return $this->state(fn (): array => ['chat_id' => $chatId, 'linked_at' => now(), 'bot_id' => (int) config('telegram.bot_id', 12345)]);
    }
}
