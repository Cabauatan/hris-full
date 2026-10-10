<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\ApplicantExamAttempt;
use App\Models\JobApplication;
use App\Models\JobOpening;
use App\Models\RecruitmentExam;
use App\Models\RecruitmentInterview;
use App\Services\Recruitment\RecruitmentService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\FormRequestTestCase;

class RecruitmentServiceTest extends FormRequestTestCase
{
    public function test_applying_ignores_client_owned_status_identity_and_hire_fields(): void
    {
        $applicant = Applicant::factory()->create();
        $other = Applicant::factory()->create();
        $opening = JobOpening::factory()->create(['title' => 'Accountant']);
        $application = app(RecruitmentService::class)->apply($applicant, [
            'job_opening_id' => $opening->id, 'desired_position' => 'Injected Position', 'applicant_id' => $other->id,
            'status' => 'hired', 'start_date' => '2026-12-01',
        ]);
        $this->assertDatabaseHas('job_applications', [
            'id' => $application->id, 'applicant_id' => $applicant->id,
            'job_opening_id' => $opening->id, 'status' => 'pending', 'desired_position' => 'Accountant', 'start_date' => null,
        ]);
    }

    public function test_exam_creation_generates_question_ids_and_derives_maximum_from_points(): void
    {
        $creator = $this->user();
        $exam = app(RecruitmentService::class)->createExam($creator, [
            'title' => 'Skills', 'passing_percentage' => 60, 'max_score' => 999,
            'created_by' => 999, 'questions' => [[
                'id' => 'client-owned', 'type' => 'multiple_choice', 'prompt' => 'Choose',
                'points' => 7, 'options' => ['A', 'B'], 'correct_option' => 1,
            ]],
        ]);
        $this->assertSame(7, $exam->max_score);
        $this->assertSame($creator->id, $exam->created_by);
        $this->assertNotSame('client-owned', $exam->questions[0]['id']);
        $this->assertDatabaseHas('recruitment_exams', ['id' => $exam->id, 'max_score' => 7]);
    }

    public function test_assignment_rechecks_fresh_application_state(): void
    {
        $application = JobApplication::factory()->approved()->create();
        $exam = RecruitmentExam::factory()->create();
        $staff = $this->user();
        JobApplication::query()->whereKey($application)->update(['status' => 'rejected']);
        try {
            app(RecruitmentService::class)->assign($application, $exam, $staff);
            $this->fail('A stale approved model must not permit assignment.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The application must be approved for this action.', $exception->getMessage());
        }
        $this->assertDatabaseCount('applicant_exam_attempts', 0);
    }

    public function test_submission_rechecks_fresh_ownership_before_recording_answers(): void
    {
        $owner = Applicant::factory()->create();
        $other = Applicant::factory()->create();
        $application = JobApplication::factory()->approved()->for($owner, 'applicant')->create();
        $attempt = ApplicantExamAttempt::factory()->for($application, 'application')->create();
        JobApplication::query()->whereKey($application)->update(['applicant_id' => $other->id]);
        try {
            app(RecruitmentService::class)->submit($attempt, $owner, $this->answers($attempt));
            $this->fail('A stale ownership authorization must not permit submission.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertDatabaseHas('applicant_exam_attempts', ['id' => $attempt->id, 'status' => 'assigned', 'answers' => null]);
    }

    public function test_submission_rechecks_disabled_applicant_without_partial_writes(): void
    {
        $owner = Applicant::factory()->create();
        $application = JobApplication::factory()->approved()->for($owner, 'applicant')->create();
        $attempt = ApplicantExamAttempt::factory()->for($application, 'application')->create();
        Applicant::query()->whereKey($owner)->update(['is_active' => false]);
        try {
            app(RecruitmentService::class)->submit($attempt, $owner, $this->answers($attempt));
            $this->fail('An inactive account must not submit an exam.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The applicant account is inactive.', $exception->getMessage());
        }
        $this->assertDatabaseHas('applicant_exam_attempts', ['id' => $attempt->id, 'status' => 'assigned', 'submitted_at' => null]);
    }

    public function test_manual_grading_rejects_out_of_range_scores_atomically(): void
    {
        $owner = Applicant::factory()->create();
        $application = JobApplication::factory()->approved()->for($owner, 'applicant')->create();
        $attempt = ApplicantExamAttempt::factory()->for($application, 'application')->create();
        $service = app(RecruitmentService::class);
        $service->submit($attempt, $owner, $this->answers($attempt));
        $written = $attempt->questions[1];
        try {
            $service->grade($attempt, $this->user(), [['question_id' => $written['id'], 'score' => 11]]);
            $this->fail('A written score cannot exceed the assigned maximum.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('grades', $exception->errors());
        }
        $this->assertDatabaseHas('applicant_exam_attempts', ['id' => $attempt->id, 'status' => 'submitted', 'score' => null, 'graded_at' => null]);
    }

    #[TestWith(['initial'])]
    #[TestWith(['final'])]
    public function test_scheduled_interview_blocks_hiring_even_with_a_stale_loaded_application(string $round): void
    {
        $application = JobApplication::factory()->approved()->create();
        $application->load('interviews');
        RecruitmentInterview::factory()->for($application, 'application')->create(['round' => $round]);

        try {
            app(RecruitmentService::class)->hire($application, $this->user(), '2026-12-01');
            $this->fail('Outstanding interviews must be completed before hiring.');
        } catch (RuntimeException $exception) {
            $this->assertSame('All scheduled interviews must be completed before hiring.', $exception->getMessage());
        }

        $this->assertDatabaseHas('job_applications', ['id' => $application->id, 'status' => 'approved', 'start_date' => null]);
    }

    public function test_completed_interviews_and_cancelled_history_allow_hired_status_without_onboarding(): void
    {
        $application = JobApplication::factory()->approved()->create();
        RecruitmentInterview::factory()->for($application, 'application')->create(['status' => 'completed']);
        RecruitmentInterview::factory()->finalRound()->cancelled()->for($application, 'application')->create();
        $hirer = $this->user();

        $hired = app(RecruitmentService::class)->hire($application, $hirer, '2026-12-01');

        $this->assertSame('hired', $hired->status);
        $this->assertSame('2026-12-01', $hired->start_date->toDateString());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseHas('job_applications', ['id' => $hired->id, 'hired_by' => $hirer->id, 'status' => 'hired', 'start_date' => '2026-12-01 00:00:00']);
    }

    /** @return list<array{question_id: string, answer: string}> */
    private function answers(ApplicantExamAttempt $attempt): array
    {
        return array_map(static fn (array $question): array => [
            'question_id' => $question['id'],
            'answer' => $question['type'] === 'multiple_choice' ? '0' : 'Organize by priority.',
        ], $attempt->questions);
    }
}
