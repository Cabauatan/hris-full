<?php

namespace Database\Factories;

use App\Models\RecruitmentInterview;
use App\Models\RecruitmentInterviewMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RecruitmentInterviewMessage>
 */
class RecruitmentInterviewMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recruitment_interview_id' => RecruitmentInterview::factory(),
            'sender_kind' => 'applicant',
            'sender_id' => fn (array $attributes): int => RecruitmentInterview::query()->findOrFail($attributes['recruitment_interview_id'])->application->applicant_id,
            'sender_name' => fake()->name(),
            'client_message_id' => (string) Str::uuid(),
            'body' => fake()->sentence(),
        ];
    }
}
