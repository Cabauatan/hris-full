<?php

namespace Database\Factories;

use App\Models\RecruitmentExam;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RecruitmentExam>
 */
class RecruitmentExamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => 'Applicant Skills Assessment',
            'instructions' => 'Answer all questions.',
            'questions' => [
                [
                    'id' => (string) Str::uuid(),
                    'type' => 'multiple_choice',
                    'prompt' => 'Which number follows 1, 2, 3?',
                    'points' => 5,
                    'options' => ['4', '5', '6'],
                    'correct_option' => 0,
                ],
                [
                    'id' => (string) Str::uuid(),
                    'type' => 'written',
                    'prompt' => 'Describe how you organize daily tasks.',
                    'points' => 10,
                ],
            ],
            'max_score' => 15,
            'passing_percentage' => 60,
            'is_active' => true,
        ];
    }
}
