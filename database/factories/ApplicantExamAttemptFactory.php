<?php

namespace Database\Factories;

use App\Models\ApplicantExamAttempt;
use App\Models\JobApplication;
use App\Models\RecruitmentExam;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplicantExamAttempt>
 */
class ApplicantExamAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_application_id' => JobApplication::factory()->approved(),
            'recruitment_exam_id' => RecruitmentExam::factory(),
            'questions' => fn (array $attributes): array => RecruitmentExam::query()->findOrFail($attributes['recruitment_exam_id'])->questions,
            'max_score' => fn (array $attributes): int => RecruitmentExam::query()->findOrFail($attributes['recruitment_exam_id'])->max_score,
            'passing_percentage' => fn (array $attributes): string => RecruitmentExam::query()->findOrFail($attributes['recruitment_exam_id'])->passing_percentage,
            'status' => 'assigned',
            'automatic_score' => 0,
        ];
    }
}
