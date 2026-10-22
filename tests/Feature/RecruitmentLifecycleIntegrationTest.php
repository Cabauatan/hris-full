<?php

namespace Tests\Feature;

use App\Models\Applicant;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\FormRequestTestCase;

class RecruitmentLifecycleIntegrationTest extends FormRequestTestCase
{
    /** @var array<string, array<string, string>> */
    private array $browserCookies = [];

    public function test_separate_staff_and_applicant_sessions_complete_recruitment_without_creating_an_employee_account(): void
    {
        $this->travelTo('2026-10-17 09:00:00');
        $hr = $this->user('recruitment.view', 'recruitment.manage', 'recruitment.grade');
        $hrEmployee = $this->employee($hr);
        $panel = $this->user('recruitment.view', 'recruitment.manage');
        $panelEmployee = $this->employee($panel);
        Mail::fake();

        $this->requestAs('hr', 'POST', '/api/v1/auth/login', [
            'employee_number' => $hrEmployee->employee_number, 'password' => 'password',
        ])->assertOk()->assertJsonPath('data.id', $hr->id);
        $this->requestAs('panel', 'POST', '/api/v1/auth/login', [
            'employee_number' => $panelEmployee->employee_number, 'password' => 'password',
        ])->assertOk()->assertJsonPath('data.id', $panel->id);

        $opening = $this->requestAs('hr', 'POST', '/api/v1/recruitment/job-openings', [
            'title' => 'Records Assistant', 'description' => 'Maintain employee records.',
            'monthly_salary' => '25000.50', 'status' => 'open', 'vacancies' => 1,
        ])->assertCreated()->json('data.id');
        $this->requestAs('applicant', 'GET', '/api/v1/careers/job-openings/'.$opening)
            ->assertOk()->assertJsonPath('data.title', 'Records Assistant')
            ->assertJsonPath('data.monthly_salary', '25000.50')->assertJsonPath('data.salary_currency', 'PHP');
        $candidateId = $this->requestAs('applicant', 'POST', '/api/v1/applicant/auth/register', [
            'first_name' => 'External', 'last_name' => 'Candidate', 'email' => 'lifecycle@example.test',
            'password' => 'applicant-password', 'password_confirmation' => 'applicant-password',
        ])->assertCreated()->json('data.id');
        $this->assertSame($hr->id, $candidateId);
        $application = $this->requestAs('applicant', 'POST', '/api/v1/applicant/applications', [
            'job_opening_id' => $opening, 'cover_letter' => 'I would like to join your team.',
        ])->assertCreated()->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.desired_position', 'Records Assistant')
            ->assertJsonPath('data.monthly_salary', '25000.50')->json('data.id');
        $this->requestAs('applicant', 'GET', '/api/v1/recruitment/applications')->assertUnauthorized();
        $this->requestAs('hr', 'GET', '/api/v1/applicant/applications')->assertUnauthorized();

        $this->requestAs('hr', 'POST', '/api/v1/recruitment/applications/'.$application.'/approve')
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $exam = $this->requestAs('hr', 'POST', '/api/v1/recruitment/exams', [
            'title' => 'Records Assessment', 'passing_percentage' => 70,
            'questions' => [
                ['type' => 'multiple_choice', 'prompt' => 'Which record is confidential?', 'points' => 3,
                    'options' => ['Public announcement', 'Employee medical record'], 'correct_option' => 1],
                ['type' => 'written', 'prompt' => 'Explain how to protect employee records.', 'points' => 7],
            ],
        ])->assertCreated()->json('data.id');
        $attempt = $this->requestAs('hr', 'POST', '/api/v1/recruitment/applications/'.$application.'/exams', [
            'recruitment_exam_id' => $exam,
        ])->assertCreated()->json('data.id');
        $questions = $this->requestAs('applicant', 'GET', '/api/v1/applicant/exams/'.$attempt)
            ->assertOk()->assertJsonMissingPath('data.questions.0.correct_option')->json('data.questions');
        $this->requestAs('applicant', 'POST', '/api/v1/applicant/exams/'.$attempt.'/submit', [
            'answers' => [
                ['question_id' => $questions[0]['id'], 'answer' => '1'],
                ['question_id' => $questions[1]['id'], 'answer' => 'Restrict access to authorized staff.'],
            ],
        ])->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.score', null);
        $this->requestAs('hr', 'POST', '/api/v1/recruitment/applications/'.$application.'/hire', [
            'start_date' => '2026-11-02',
        ])->assertConflict();
        $this->requestAs('hr', 'POST', '/api/v1/recruitment/attempts/'.$attempt.'/grade', [
            'grades' => [['question_id' => $questions[1]['id'], 'score' => 5, 'feedback' => 'Clear explanation.']],
        ])->assertOk()->assertJsonPath('data.status', 'graded')->assertJsonPath('data.score', 8);
        $this->requestAs('applicant', 'GET', '/api/v1/applicant/exams/'.$attempt)
            ->assertOk()->assertJsonPath('data.percentage', 80)->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.grades.0.automatic', true)->assertJsonPath('data.grades.1.automatic', false)
            ->assertJsonPath('data.grades.1.feedback', 'Clear explanation.')
            ->assertJsonMissingPath('data.questions.0.correct_option');

        $this->completeInterviewRound($application, 'initial', '2026-10-17 09:30:00', [$hr->id]);
        $this->completeInterviewRound($application, 'final', '2026-10-17 10:00:00', [$hr->id, $panel->id]);

        $this->requestAs('hr', 'POST', '/api/v1/recruitment/applications/'.$application.'/hire', [
            'start_date' => '2026-11-02', 'remarks' => 'Report to HR at 8 AM.',
        ])->assertOk()->assertJsonPath('data.status', 'hired')->assertJsonPath('data.start_date', '2026-11-02');
        $this->requestAs('applicant', 'GET', '/api/v1/applicant/applications/'.$application)
            ->assertOk()->assertJsonPath('data.status', 'hired')->assertJsonPath('data.start_date', '2026-11-02')
            ->assertJsonPath('data.desired_position', 'Records Assistant')->assertJsonPath('data.monthly_salary', '25000.50')
            ->assertJsonPath('data.attempts.0.score', 8)->assertJsonMissingPath('data.attempts.0.questions.0.correct_option');
        $this->requestAs('applicant', 'GET', '/api/v1/ess/profile')->assertUnauthorized();
        $this->assertDatabaseHas('job_applications', [
            'id' => $application, 'applicant_id' => $candidateId, 'status' => 'hired', 'hired_by' => $hr->id,
            'start_date' => '2026-11-02 00:00:00',
        ]);
        $this->assertDatabaseCount('applicants', 1);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('employees', 2);
        $this->assertSame('lifecycle@example.test', Applicant::query()->findOrFail($candidateId)->email);
        Mail::assertNothingSent();
    }

    /** @param list<int> $interviewerIds */
    private function completeInterviewRound(int $application, string $round, string $scheduledAt, array $interviewerIds): void
    {
        $interview = $this->requestAs('hr', 'POST', '/api/v1/recruitment/applications/'.$application.'/interviews', [
            'round' => $round, 'scheduled_at' => str_replace(' ', 'T', $scheduledAt).'+08:00',
            'duration_minutes' => 30, 'interviewer_ids' => $interviewerIds, 'private_notes' => 'Internal '.$round.' evaluation.',
        ])->assertCreated()->assertJsonPath('data.status', 'scheduled')->json('data.id');
        $this->requestAs('applicant', 'GET', '/api/v1/applicant/interviews/'.$interview)
            ->assertOk()->assertJsonPath('data.round', $round)->assertJsonMissingPath('data.private_notes');
        $this->travelTo($scheduledAt);
        $this->requestAs('applicant', 'POST', '/api/v1/applicant/interviews/'.$interview.'/join')
            ->assertOk()->assertJsonCount(count($interviewerIds) + 1, 'data.participants');
        $this->requestAs('hr', 'POST', '/api/v1/recruitment/interviews/'.$interview.'/join')->assertOk();

        if ($round === 'final') {
            $this->requestAs('panel', 'POST', '/api/v1/recruitment/interviews/'.$interview.'/join')
                ->assertOk()->assertJsonCount(3, 'data.participants');
        }

        $this->requestAs('hr', 'POST', '/api/v1/recruitment/applications/'.$application.'/hire', [
            'start_date' => '2026-11-02',
        ])->assertConflict();
        $this->requestAs('hr', 'POST', '/api/v1/recruitment/interviews/'.$interview.'/complete')
            ->assertOk()->assertJsonPath('data.status', 'completed');
        $this->requestAs('applicant', 'POST', '/api/v1/applicant/interviews/'.$interview.'/join')->assertConflict();
        $this->assertDatabaseHas('recruitment_interviews', ['id' => $interview, 'round' => $round, 'status' => 'completed']);
    }

    /** @param array<string, mixed> $payload */
    private function requestAs(string $browser, string $method, string $url, array $payload = []): TestResponse
    {
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->withCredentials()->withUnencryptedCookies($this->browserCookies[$browser] ?? []);
        $this->app['session']->driver()->flush();
        $this->app['auth']->forgetGuards();
        $response = $this->json($method, $url, $payload);

        foreach ($response->headers->getCookies() as $cookie) {
            $this->browserCookies[$browser][$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    }
}
