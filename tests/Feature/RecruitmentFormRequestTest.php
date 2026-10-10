<?php

namespace Tests\Feature;

use App\Http\Requests\HireJobApplicationRequest;
use App\Http\Requests\StoreRecruitmentExamRequest;
use App\Models\JobApplication;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class RecruitmentFormRequestTest extends FormRequestTestCase
{
    public function test_hr_can_validate_a_mixed_exam_without_client_question_ids(): void
    {
        $input = self::examInput();
        $validated = $this->validateRequest(StoreRecruitmentExamRequest::class, $input, $this->user('recruitment.manage'));

        $this->assertSame($input, $validated);
    }

    #[DataProvider('invalidExamFields')]
    public function test_invalid_exam_fields_fail_before_persistence(string $field, mixed $value, string $error): void
    {
        $input = self::examInput();
        Arr::set($input, $field, $value);
        $this->assertInvalidRequest(StoreRecruitmentExamRequest::class, $input, $this->user('recruitment.manage'), fields: [$error]);
        $this->assertDatabaseCount('recruitment_exams', 0);
    }

    /** @return array<string, array{string, mixed, string}> */
    public static function invalidExamFields(): array
    {
        return [
            'missing title' => ['title', null, 'title'],
            'oversized title' => ['title', str_repeat('a', 181), 'title'],
            'invalid active flag' => ['is_active', 'yes', 'is_active'],
            'negative passing percentage' => ['passing_percentage', -1, 'passing_percentage'],
            'excessive passing percentage' => ['passing_percentage', 101, 'passing_percentage'],
            'passing precision' => ['passing_percentage', 60.001, 'passing_percentage'],
            'no questions' => ['questions', [], 'questions'],
            'too many questions' => ['questions', array_fill(0, 51, ['type' => 'written', 'prompt' => 'Explain', 'points' => 1]), 'questions'],
            'unknown type' => ['questions.0.type', 'essay', 'questions.0.type'],
            'empty prompt' => ['questions.0.prompt', '', 'questions.0.prompt'],
            'prompt too long' => ['questions.0.prompt', str_repeat('a', 5001), 'questions.0.prompt'],
            'zero points' => ['questions.0.points', 0, 'questions.0.points'],
            'fractional maximum' => ['questions.0.points', 1.5, 'questions.0.points'],
            'too many points' => ['questions.0.points', 101, 'questions.0.points'],
            'too few options' => ['questions.0.options', ['A'], 'questions.0.options'],
            'too many options' => ['questions.0.options', array_fill(0, 9, 'A'), 'questions.0.options'],
            'options are required' => ['questions.0', ['type' => 'multiple_choice', 'prompt' => 'Choose', 'points' => 1, 'correct_option' => 0], 'questions.0.options'],
            'correct option is required' => ['questions.0', ['type' => 'multiple_choice', 'prompt' => 'Choose', 'points' => 1, 'options' => ['A', 'B']], 'questions.0.correct_option'],
            'correct option must exist' => ['questions.0.correct_option', 2, 'questions.0.correct_option'],
            'negative option' => ['questions.0.correct_option', -1, 'questions.0.correct_option'],
            'written options prohibited' => ['questions.1.options', ['A', 'B'], 'questions.1.options'],
            'written key prohibited' => ['questions.1.correct_option', 0, 'questions.1.correct_option'],
            'client id prohibited' => ['questions.0.id', 'client-owned', 'questions.0'],
        ];
    }

    public function test_staff_without_manage_permission_cannot_validate_an_exam_definition(): void
    {
        $this->assertUnauthorizedRequest(StoreRecruitmentExamRequest::class, self::examInput(), $this->user('recruitment.view'));
    }

    public function test_hiring_requires_a_real_calendar_date(): void
    {
        $application = JobApplication::factory()->approved()->create();
        $this->assertInvalidRequest(HireJobApplicationRequest::class, ['start_date' => '2026-02-30'], $this->user('recruitment.manage'), ['jobApplication' => $application], ['start_date']);
        $this->assertNull($application->fresh()->start_date);
    }

    /** @return array<string, mixed> */
    private static function examInput(): array
    {
        return [
            'title' => 'Applicant Assessment', 'passing_percentage' => 60,
            'questions' => [
                ['type' => 'multiple_choice', 'prompt' => 'Choose', 'points' => 5, 'options' => ['A', 'B'], 'correct_option' => 1],
                ['type' => 'written', 'prompt' => 'Explain', 'points' => 10],
            ],
        ];
    }
}
