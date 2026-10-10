<?php

namespace Database\Factories;

use App\Models\JobApplication;
use App\Models\RecruitmentInterview;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RecruitmentInterview>
 */
class RecruitmentInterviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'job_application_id' => JobApplication::factory()->approved(),
            'round' => 'initial',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay()->setTime(9, 0),
            'duration_minutes' => 30,
            'private_notes' => null,
        ];
    }

    public function finalRound(): static
    {
        return $this->state(fn (array $attributes): array => ['round' => 'final']);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'cancelled']);
    }
}
