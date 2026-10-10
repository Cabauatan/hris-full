<?php

namespace Database\Factories;

use App\Models\TelegramDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TelegramDelivery>
 */
class TelegramDeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'resource_type' => 'user',
            'recipient_type' => 'user',
            'recipient_id' => User::factory()->state(['uuid' => (string) Str::uuid(), 'is_active' => true]),
            'resource_id' => fn (array $attributes): int => (int) $attributes['recipient_id'],
            'event' => 'password_changed',
            'event_id' => (string) Str::uuid(),
            'connection_key' => (string) Str::uuid(),
            'source_version' => null,
            'occurred_at' => now(),
            'status' => 'queued',
            'sent_at' => null,
        ];
    }
}
