<?php

namespace Database\Factories;

use App\Models\EmailDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmailDelivery>
 */
class EmailDeliveryFactory extends Factory
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
            'recipient_id' => User::factory()->state(['uuid' => (string) Str::uuid(), 'is_active' => true]),
            'resource_id' => fn (array $attributes): int => (int) $attributes['recipient_id'],
            'event' => 'password_changed',
            'event_id' => (string) Str::uuid(),
            'recipient_type' => 'user',
            'source_version' => null,
            'occurred_at' => now(),
            'status' => 'queued',
            'sent_at' => null,
        ];
    }
}
