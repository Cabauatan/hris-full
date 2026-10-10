<?php

namespace Database\Factories;

use App\Models\JobOpening;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobOpening>
 */
class JobOpeningFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => 'Administrative Assistant',
            'description' => 'Provide administrative support and maintain office records.',
            'requirements' => null,
            'location' => null,
            'employment_type' => null,
            'vacancies' => 1,
            'status' => 'open',
            'closes_on' => null,
            'monthly_salary' => '25000.00',
            'created_by' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'closed']);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => ['closes_on' => now()->subDay()->toDateString()]);
    }
}
