<?php

namespace App\Services\Recruitment;

use App\Events\InterviewEnded;
use App\Models\Applicant;
use App\Models\ApplicantExamAttempt;
use App\Models\JobApplication;
use App\Models\JobOpening;
use App\Models\RecruitmentExam;
use App\Models\RecruitmentInterview;
use App\Models\User;
use App\Services\Notification\OutboundNotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RecruitmentService
{
    public function __construct(private readonly OutboundNotificationService $updates) {}

    /** @param array<string, mixed> $input */
    public function apply(Applicant $applicant, array $input): JobApplication
    {
        return DB::transaction(function () use ($applicant, $input): JobApplication {
            $applicant = Applicant::query()->lockForUpdate()->findOrFail($applicant->id);
            $this->ensureApplicantActive($applicant);

            if ($applicant->applications()->whereIn('status', ['pending', 'approved', 'hired'])->exists()) {
                throw new RuntimeException('You already have an active application.');
            }

            $opening = JobOpening::query()->lockForUpdate()->find($input['job_opening_id'] ?? null);
            if ($opening === null || ! $opening->acceptingApplications()) {
                throw ValidationException::withMessages(['job_opening_id' => 'This position is no longer accepting applications.']);
            }

            $application = $applicant->applications()->create([
                'job_opening_id' => $opening->id,
                'desired_position' => $opening->title,
                'monthly_salary' => $opening->monthly_salary,
                'cover_letter' => $input['cover_letter'] ?? null,
                'status' => 'pending',
            ]);
            $this->queueApplicationUpdates($application, 'submitted');

            return $application;
        });
    }

    public function approve(JobApplication $application, User $reviewer, ?string $remarks = null): JobApplication
    {
        return DB::transaction(function () use ($application, $reviewer, $remarks): JobApplication {
            $application = $this->lockApplication($application);
            if ($application->status !== 'pending') {
                throw new RuntimeException('Only pending applications can be approved.');
            }
            $application->update(['status' => 'approved', 'remarks' => $remarks, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);
            $this->queueApplicationUpdates($application, 'approved');

            return $application;
        });
    }

    public function reject(JobApplication $application, User $reviewer, ?string $remarks = null): JobApplication
    {
        return DB::transaction(function () use ($application, $reviewer, $remarks): JobApplication {
            $application = $this->lockApplication($application);
            if (! in_array($application->status, ['pending', 'approved'], true)) {
                throw new RuntimeException('Only pending or approved applications can be rejected.');
            }
            $application->update(['status' => 'rejected', 'remarks' => $remarks, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);
            $this->queueApplicationUpdates($application, 'rejected');

            $interviews = $application->interviews()->where('status', 'scheduled')->lockForUpdate()->get();
            foreach ($interviews as $interview) {
                $interview->update(['status' => 'cancelled', 'ended_at' => now(), 'completed_by' => $reviewer->id]);
                $this->queueCancelledInterviewUpdates($interview, $application->applicant);
                event(new InterviewEnded($interview->uuid, 'cancelled'));
            }

            return $application;
        });
    }

    /** @param array<string, mixed> $input */
    public function createExam(User $creator, array $input): RecruitmentExam
    {
        $questions = [];
        foreach ($input['questions'] as $question) {
            $item = Arr::only($question, ['type', 'prompt', 'points']);
            $item['id'] = (string) Str::uuid();
            $item['points'] = (int) $question['points'];
            if ($question['type'] === 'multiple_choice') {
                $item['options'] = array_values($question['options']);
                $item['correct_option'] = (int) $question['correct_option'];
                if (! array_key_exists($item['correct_option'], $item['options'])) {
                    throw ValidationException::withMessages(['questions' => 'Every multiple-choice question must have a valid correct option.']);
                }
            }
            $questions[] = $item;
        }

        $maxScore = array_sum(array_column($questions, 'points'));
        if ($maxScore < 1) {
            throw ValidationException::withMessages(['questions' => 'The exam must have a positive maximum score.']);
        }

        return RecruitmentExam::query()->create([
            'title' => trim($input['title']), 'instructions' => $input['instructions'] ?? null,
            'questions' => $questions, 'max_score' => $maxScore,
            'passing_percentage' => $input['passing_percentage'],
            'is_active' => $input['is_active'] ?? true, 'created_by' => $creator->id,
        ]);
    }

    public function assign(JobApplication $application, RecruitmentExam $exam, User $assigner): ApplicantExamAttempt
    {
        return DB::transaction(function () use ($application, $exam, $assigner): ApplicantExamAttempt {
            $application = $this->lockApplication($application);
            $this->ensureApproved($application);
            $exam = RecruitmentExam::query()->lockForUpdate()->findOrFail($exam->id);
            if (! $exam->is_active) {
                throw new RuntimeException('Only active exams can be assigned.');
            }
            if ($application->attempts()->where('recruitment_exam_id', $exam->id)->exists()) {
                throw new RuntimeException('This exam has already been assigned to the application.');
            }

            $attempt = $application->attempts()->create([
                'recruitment_exam_id' => $exam->id, 'assigned_by' => $assigner->id,
                'questions' => $exam->questions, 'max_score' => $exam->max_score,
                'passing_percentage' => $exam->passing_percentage,
                'status' => 'assigned', 'automatic_score' => 0,
            ]);
            $this->updates->queue('applicant_exam_attempt', $attempt->id, 'assigned', $application->applicant, 'hris/recruitment/applicant_exam_attempt/'.$attempt->id.'/assigned');

            return $attempt;
        });
    }

    /** @param list<array{question_id: string, answer: string}> $answers */
    public function submit(ApplicantExamAttempt $attempt, Applicant $applicant, array $answers): ApplicantExamAttempt
    {
        return DB::transaction(function () use ($attempt, $applicant, $answers): ApplicantExamAttempt {
            [$application, $attempt] = $this->lockAttempt($attempt);
            abort_unless($application->applicant_id === $applicant->id, 404);
            $this->ensureApplicantActive(Applicant::query()->findOrFail($applicant->id));
            $this->ensureApproved($application);
            if ($attempt->status !== 'assigned') {
                throw new RuntimeException('This exam has already been submitted.');
            }

            $questions = $attempt->questions;
            $this->ensureExactQuestionIds($answers, $questions, 'answers');
            $answerMap = array_column($answers, 'answer', 'question_id');
            foreach ($questions as $question) {
                $answer = $answerMap[$question['id']];
                if ($question['type'] === 'multiple_choice'
                    && (! ctype_digit($answer) || ! array_key_exists((int) $answer, $question['options']))) {
                    throw ValidationException::withMessages(['answers' => 'Select a valid option for every multiple-choice question.']);
                }
            }

            $automaticGrades = $this->automaticGrades($questions, $answerMap);
            $automaticScore = array_sum(array_column($automaticGrades, 'score'));
            $hasWritten = count($automaticGrades) !== count($questions);
            $attempt->update([
                'answers' => array_map(static fn (array $answer): array => Arr::only($answer, ['question_id', 'answer']), $answers),
                'grades' => $automaticGrades, 'automatic_score' => $automaticScore,
                'status' => $hasWritten ? 'submitted' : 'graded', 'submitted_at' => now(),
                'graded_at' => $hasWritten ? null : now(), 'graded_by' => null,
            ] + ($hasWritten ? ['score' => null, 'percentage' => null, 'passed' => null] : $this->result($attempt, $automaticScore)));
            if (! $hasWritten) {
                $this->updates->queue('applicant_exam_attempt', $attempt->id, 'result_ready', $application->applicant, 'hris/recruitment/applicant_exam_attempt/'.$attempt->id.'/result_ready');
            }

            return $attempt;
        });
    }

    /** @param list<array{question_id: string, score: int|float|string, feedback?: ?string}> $grades */
    public function grade(ApplicantExamAttempt $attempt, User $grader, array $grades): ApplicantExamAttempt
    {
        return DB::transaction(function () use ($attempt, $grader, $grades): ApplicantExamAttempt {
            [$application, $attempt] = $this->lockAttempt($attempt);
            $this->ensureApproved($application);
            if ($attempt->status !== 'submitted') {
                throw new RuntimeException('Only submitted exams awaiting manual grading can be graded.');
            }

            $questions = $attempt->questions;
            $written = array_values(array_filter($questions, static fn (array $question): bool => $question['type'] === 'written'));
            $this->ensureExactQuestionIds($grades, $written, 'grades');
            $gradeMap = array_column($grades, null, 'question_id');
            $answerMap = array_column($attempt->answers, 'answer', 'question_id');
            $automaticGrades = $this->automaticGrades($questions, $answerMap);
            $automaticScore = array_sum(array_column($automaticGrades, 'score'));
            $finalGrades = array_column($automaticGrades, null, 'question_id');
            $score = $automaticScore;
            foreach ($written as $question) {
                $grade = $gradeMap[$question['id']];
                $points = (float) $grade['score'];
                if (! is_numeric($grade['score']) || ! is_finite($points) || $points < 0 || $points > $question['points']) {
                    throw ValidationException::withMessages(['grades' => 'Written scores must be between zero and the question maximum.']);
                }
                $points = round($points, 2);
                $score += $points;
                $finalGrades[$question['id']] = [
                    'question_id' => $question['id'], 'score' => $points,
                    'feedback' => $grade['feedback'] ?? null, 'automatic' => false,
                ];
            }

            $attempt->update([
                'grades' => array_map(static fn (array $question): array => $finalGrades[$question['id']], $questions),
                'automatic_score' => $automaticScore, 'status' => 'graded', 'graded_at' => now(), 'graded_by' => $grader->id,
            ] + $this->result($attempt, round($score, 2)));
            $this->updates->queue('applicant_exam_attempt', $attempt->id, 'result_ready', $application->applicant, 'hris/recruitment/applicant_exam_attempt/'.$attempt->id.'/result_ready');

            return $attempt;
        });
    }

    public function hire(JobApplication $application, User $hirer, string $startDate, ?string $remarks = null): JobApplication
    {
        return DB::transaction(function () use ($application, $hirer, $startDate, $remarks): JobApplication {
            $application = $this->lockApplication($application);
            $this->ensureApproved($application);
            if ($application->attempts()->where('status', '!=', 'graded')->exists()) {
                throw new RuntimeException('All assigned exams must be graded before hiring.');
            }
            if ($application->interviews()->whereNotIn('status', ['completed', 'cancelled'])->exists()) {
                throw new RuntimeException('All scheduled interviews must be completed before hiring.');
            }
            $application->update([
                'status' => 'hired', 'hired_by' => $hirer->id, 'hired_at' => now(),
                'start_date' => $startDate, 'remarks' => $remarks ?? $application->remarks,
            ]);
            $this->queueApplicationUpdates($application, 'hired');

            return $application;
        });
    }

    private function queueApplicationUpdates(JobApplication $application, string $event): void
    {
        if (! $this->updates->enabled()) {
            return;
        }

        $eventId = 'hris/recruitment/job_application/'.$application->id.'/'.$event;
        $this->updates->queue('job_application', $application->id, $event, $application->applicant, $eventId);
        if ($event !== 'submitted') {
            return;
        }

        $managers = User::query()->where('is_active', true);
        foreach (['recruitment.view', 'recruitment.manage'] as $permission) {
            $managers->where(function (Builder $users) use ($permission): void {
                $users->whereHas('permissions', fn (Builder $permissions): Builder => $permissions->where('code', $permission)->where('is_active', true))
                    ->orWhereHas('roles', fn (Builder $roles): Builder => $roles->where('is_active', true)
                        ->whereHas('permissions', fn (Builder $permissions): Builder => $permissions->where('code', $permission)->where('is_active', true)));
            });
        }
        $managers->chunkById(100, function (Collection $users) use ($application, $eventId): void {
            foreach ($users as $user) {
                if ($user->hasPermission('recruitment.view') && $user->hasPermission('recruitment.manage')) {
                    $this->updates->queue('job_application', $application->id, 'submitted', $user, $eventId);
                }
            }
        });
    }

    private function queueCancelledInterviewUpdates(RecruitmentInterview $interview, Applicant $applicant): void
    {
        $eventId = 'hris/recruitment/recruitment_interview/'.$interview->id.'/cancelled';
        $version = $interview->scheduled_at->format('Y-m-d H:i:s');
        $this->updates->queue('recruitment_interview', $interview->id, 'cancelled', $applicant, $eventId, $version);
        foreach ($interview->interviewers as $interviewer) {
            if ($interviewer->hasPermission('recruitment.view') && $interviewer->hasPermission('recruitment.manage')) {
                $this->updates->queue('recruitment_interview', $interview->id, 'cancelled', $interviewer, $eventId, $version);
            }
        }
    }

    private function lockApplication(JobApplication $application): JobApplication
    {
        return JobApplication::query()->lockForUpdate()->findOrFail($application->id);
    }

    /** @return array{JobApplication, ApplicantExamAttempt} */
    private function lockAttempt(ApplicantExamAttempt $attempt): array
    {
        $application = JobApplication::query()->lockForUpdate()->findOrFail($attempt->job_application_id);
        $attempt = ApplicantExamAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
        abort_unless($attempt->job_application_id === $application->id, 404);

        return [$application, $attempt];
    }

    private function ensureApproved(JobApplication $application): void
    {
        if ($application->status !== 'approved') {
            throw new RuntimeException('The application must be approved for this action.');
        }
    }

    private function ensureApplicantActive(Applicant $applicant): void
    {
        if (! $applicant->is_active) {
            throw new RuntimeException('The applicant account is inactive.');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $input
     * @param  list<array<string, mixed>>  $questions
     */
    private function ensureExactQuestionIds(array $input, array $questions, string $field): void
    {
        $provided = array_column($input, 'question_id');
        $expected = array_column($questions, 'id');
        if (count($provided) !== count($expected)
            || count(array_unique($provided)) !== count($expected)
            || array_diff($expected, $provided) !== []) {
            throw ValidationException::withMessages([$field => 'Provide each required question exactly once.']);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     * @param  array<string, string>  $answers
     * @return list<array{question_id: string, score: int, feedback: null, automatic: bool}>
     */
    private function automaticGrades(array $questions, array $answers): array
    {
        $grades = [];
        foreach ($questions as $question) {
            if ($question['type'] === 'multiple_choice') {
                $grades[] = [
                    'question_id' => $question['id'],
                    'score' => (int) $answers[$question['id']] === $question['correct_option'] ? $question['points'] : 0,
                    'feedback' => null, 'automatic' => true,
                ];
            }
        }

        return $grades;
    }

    /** @return array{score: float, percentage: float, passed: bool} */
    private function result(ApplicantExamAttempt $attempt, float $score): array
    {
        $percentage = round($score / $attempt->max_score * 100, 2);

        return ['score' => $score, 'percentage' => $percentage, 'passed' => $percentage >= (float) $attempt->passing_percentage];
    }
}
