<?php

namespace Database\Factories;

use App\Models\TelegramBotState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramBotState>
 */
class TelegramBotStateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bot_id' => fake()->unique()->numberBetween(10000, 9999999),
            'next_update_id' => 0,
        ];
    }
}
