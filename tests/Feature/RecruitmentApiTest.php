<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\ApplicantExamAttempt;
use App\Models\Employee;
use App\Models\JobApplication;
use App\Models\JobOpening;
use App\Models\RecruitmentExam;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\FormRequestTestCase;

class RecruitmentApiTest extends FormRequestTestCase
{
    #[DataProvider('protectedEndpoints')]
    public function test_guest_receives_401(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized();
    }

    public static function protectedEndpoints(): array
    {
        return [
            'candidate applications' => ['GET', '/api/v1/applicant/applications'],
            'candidate application submission' => ['POST', '/api/v1/applicant/applications'],
            'candidate application details' => ['GET', '/api/v1/applicant/applications/999999'],
            'candidate exam details' => ['GET', '/api/v1/applicant/exams/999999'],
            'candidate exam submission' => ['POST', '/api/v1/applicant/exams/999999/submit'],
            'staff applications' => ['GET', '/api/v1/recruitment/applications'],
            'staff application details' => ['GET', '/api/v1/recruitment/applications/999999'],
            'staff approval' => ['POST', '/api/v1/recruitment/applications/999999/approve'],
            'staff rejection' => ['POST', '/api/v1/recruitment/applications/999999/reject'],
            'staff hiring' => ['POST', '/api/v1/recruitment/applications/999999/hire'],
            'staff assignment' => ['POST', '/api/v1/recruitment/applications/999999/exams'],
            'staff exams' => ['GET', '/api/v1/recruitment/exams'],
            'staff exam creation' => ['POST', '/api/v1/recruitment/exams'],
            'staff exam details' => ['GET', '/api/v1/recruitment/exams/999999'],
            'staff attempt details' => ['GET', '/api/v1/recruitment/attempts/999999'],
            'staff grading' => ['POST', '/api/v1/recruitment/attempts/999999/grade'],
        ];
    }

    public function test_employee_identity_cannot_use_applicant_portal_even_with_colliding_numeric_id(): void
    {
        $staff = $this->user('recruitment.view', 'recruitment.manage', 'recruitment.grade');
        $candidate = Applicant::factory()->create(['id' => $staff->id]);
        $application = $this->createJobApplication($candidate);

        $this->asStaff($staff)->getJson('/api/v1/applicant/applications')->assertUnauthorized();
        $this->getJson('/api/v1/applicant/applications/'.$application->id)->assertUnauthorized();
        $this->postJson('/api/v1/applicant/applications', self::applicationInput())->assertUnauthorized();

        $this->assertDatabaseCount('job_applications', 1);
    }

    #[TestWith(['/api/v1/recruitment/applications'])]
    #[TestWith(['/api/v1/recruitment/exams'])]
    #[TestWith(['/api/v1/ess/profile'])]
    public function test_applicant_identity_cannot_use_employee_or_staff_api_even_with_colliding_numeric_id(string $uri): void
    {
        $staff = $this->user('recruitment.view', 'recruitment.manage', 'employees.view');
        $this->employee($staff);
        $candidate = Applicant::factory()->create(['id' => $staff->id]);

        $this->asApplicant($candidate)->getJson($uri)->assertUnauthorized();
    }

    public function test_inactive_applicant_receives_403_without_creating_an_application(): void
    {
        $candidate = Applicant::factory()->create(['is_active' => false]);

        $this->asApplicant($candidate)->postJson('/api/v1/applicant/applications', self::applicationInput())
            ->assertForbidden();

        $this->assertDatabaseCount('job_applications', 0);
    }

    public function test_submission_belongs_to_authenticated_applicant_and_ignores_privileged_fields(): void
    {
        $this->travelTo('2026-10-06 09:00:00');
        $candidate = Applicant::factory()->create();
        $other = Applicant::factory()->create();
        $staff = $this->user('recruitment.manage');
        $usersBefore = User::query()->count();
        $employeesBefore = Employee::query()->count();

        $response = $this->asApplicant($candidate)->postJson('/api/v1/applicant/applications', self::applicationInput() + [
            'desired_position' => 'Client-injected position',
            'applicant_id' => $other->id,
            'status' => 'hired',
            'start_date' => '2026-01-01',
            'reviewed_by' => $staff->id,
            'hired_by' => $staff->id,
            'remarks' => 'Injected decision',
            'employee_number' => 'INJECTED-001',
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.start_date', null)->assertJsonPath('data.desired_position', 'Administrative Assistant');
        $this->assertDatabaseHas('job_applications', [
            'id' => $response->json('data.id'), 'applicant_id' => $candidate->id,
            'status' => 'pending', 'start_date' => null, 'reviewed_by' => null, 'hired_by' => null, 'remarks' => null,
        ]);
        $this->assertSame($usersBefore, User::query()->count());
        $this->assertSame($employeesBefore, Employee::query()->count());
        $this->assertNoAnswerKeys($response->json());
    }

    public function test_missing_application_opening_returns_422_without_persisting(): void
    {
        $this->asApplicant(Applicant::factory()->create())->postJson('/api/v1/applicant/applications', [])
            ->assertUnprocessable()->assertJsonValidationErrors('job_opening_id');

        $this->assertDatabaseCount('job_applications', 0);
    }

    public function test_applicant_index_and_show_include_only_owned_applications(): void
    {
        $candidate = Applicant::factory()->create();
        $own = $this->createJobApplication($candidate);
        $other = $this->createJobApplication(Applicant::factory()->create());

        $response = $this->asApplicant($candidate)->getJson('/api/v1/applicant/applications');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson('/api/v1/applicant/applications/'.$own->id)->assertOk()->assertJsonPath('data.id', $own->id);
        $this->getJson('/api/v1/applicant/applications/'.$other->id)->assertNotFound();
    }

    #[TestWith(['pending'])]
    #[TestWith(['approved'])]
    #[TestWith(['hired'])]
    public function test_an_existing_active_application_returns_409_for_reapplication(string $status): void
    {
        $candidate = Applicant::factory()->create();
        $existing = $this->createJobApplication($candidate);
        $existing->update(['status' => $status]);

        $this->asApplicant($candidate)->postJson('/api/v1/applicant/applications', self::applicationInput())
            ->assertConflict();

        $this->assertDatabaseCount('job_applications', 1);
        $this->assertSame($status, $existing->fresh()->status);
    }

    public function test_rejected_applicant_can_reapply_and_retains_the_previous_application(): void
    {
        $candidate = Applicant::factory()->create();
        $previous = $this->createJobApplication($candidate);
        $staff = $this->user('recruitment.manage');
        $this->asStaff($staff)->postJson('/api/v1/recruitment/applications/'.$previous->id.'/reject', [
            'remarks' => 'Consider applying to a different opening.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $response = $this->asApplicant($candidate)->postJson('/api/v1/applicant/applications', [
            'job_opening_id' => JobOpening::factory()->create(['title' => 'Records Assistant'])->id,
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.desired_position', 'Records Assistant');
        $this->assertNotSame($previous->id, $response->json('data.id'));
        $this->assertSame('rejected', $previous->fresh()->status);
        $this->assertDatabaseCount('job_applications', 2);
    }

    public function test_staff_index_filters_status_and_search_and_includes_safe_applicant_details(): void
    {
        $candidate = Applicant::factory()->create(['first_name' => 'Visible', 'last_name' => 'Candidate']);
        $matching = $this->createJobApplication($candidate);
        $matching->update(['status' => 'approved']);
        $this->createJobApplication(Applicant::factory()->create());
        $staff = $this->user('recruitment.view');

        $response = $this->asStaff($staff)->getJson('/api/v1/recruitment/applications?status=approved&search=Visible');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonPath('data.0.applicant.id', $candidate->id)
            ->assertJsonPath('data.0.applicant.email', $candidate->email);
        $this->assertStringNotContainsString($candidate->password, $response->getContent());
        $this->assertNoAnswerKeys($response->json());
    }

    public function test_staff_without_recruitment_permission_receives_403(): void
    {
        $staff = $this->user('employees.view');
        $application = $this->createJobApplication(Applicant::factory()->create());

        $this->asStaff($staff)->getJson('/api/v1/recruitment/applications')->assertForbidden();
        $this->postJson('/api/v1/recruitment/applications/'.$application->id.'/approve', [])->assertForbidden();
        $this->postJson('/api/v1/recruitment/exams', self::examInput())->assertForbidden();

        $this->assertSame('pending', $application->fresh()->status);
        $this->assertDatabaseCount('recruitment_exams', 0);
    }

    #[DataProvider('staffPermissionMatrix')]
    public function test_policies_keep_view_manage_and_grade_permissions_separate(array $permissions, bool $view, bool $manage, bool $grade): void
    {
        $staff = $this->user(...$permissions);
        $application = JobApplication::factory()->approved()->create();
        $exam = RecruitmentExam::factory()->create();
        $attempt = ApplicantExamAttempt::factory()->for($application, 'application')->for($exam, 'exam')->create();
        $gate = Gate::forUser($staff);

        $this->assertSame($view, $gate->allows('viewAny', JobApplication::class));
        $this->assertSame($view, $gate->allows('view', $application));
        $this->assertSame($view, $gate->allows('viewAny', RecruitmentExam::class));
        $this->assertSame($view, $gate->allows('view', $exam));
        $this->assertSame($view, $gate->allows('view', $attempt));
        $this->assertSame($manage, $gate->allows('create', RecruitmentExam::class));

        foreach (['approve', 'reject', 'assignExam', 'hire'] as $ability) {
            $this->assertSame($manage, $gate->allows($ability, $application), $ability);
        }

        $this->assertSame($grade, $gate->allows('grade', $attempt));
        $this->assertFalse($gate->allows('create', JobApplication::class));
        $this->assertFalse($gate->allows('submit', $attempt));
    }

    public static function staffPermissionMatrix(): array
    {
        return [
            'no recruitment permissions' => [[], false, false, false],
            'view only' => [['recruitment.view'], true, false, false],
            'manage only' => [['recruitment.manage'], false, true, false],
            'grade only' => [['recruitment.grade'], false, false, true],
            'all recruitment permissions' => [['recruitment.view', 'recruitment.manage', 'recruitment.grade'], true, true, true],
        ];
    }

    public function test_applicant_policies_use_model_identity_and_hide_foreign_resources_with_404(): void
    {
        $staff = $this->staff();
        $candidate = Applicant::factory()->create(['id' => $staff->id]);
        $own = JobApplication::factory()->for($candidate)->approved()->create();
        $foreign = JobApplication::factory()->approved()->create();
        $ownAttempt = ApplicantExamAttempt::factory()->for($own, 'application')->create();
        $foreignAttempt = ApplicantExamAttempt::factory()->for($foreign, 'application')->create();
        $gate = Gate::forUser($candidate);

        $this->assertTrue($gate->allows('create', JobApplication::class));
        $this->assertTrue($gate->allows('viewAny', JobApplication::class));
        $this->assertTrue($gate->allows('view', $own));
        $this->assertTrue($gate->allows('view', $ownAttempt));
        $this->assertTrue($gate->allows('submit', $ownAttempt));
        $this->assertSame(404, $gate->inspect('view', $foreign)->status());
        $this->assertSame(404, $gate->inspect('view', $foreignAttempt)->status());
        $this->assertSame(404, $gate->inspect('submit', $foreignAttempt)->status());
        $this->assertFalse($gate->allows('approve', $own));
        $this->assertFalse($gate->allows('hire', $own));
        $this->assertFalse($gate->allows('grade', $ownAttempt));
        $this->assertFalse($gate->allows('viewAny', RecruitmentExam::class));
        $this->assertFalse($gate->allows('create', RecruitmentExam::class));
        $this->assertFalse(Gate::forUser($staff)->allows('submit', $ownAttempt));
    }

    #[TestWith(['super_admin'])]
    #[TestWith(['hr_admin'])]
    #[TestWith(['hr_staff'])]
    public function test_seeded_super_admin_and_hr_roles_receive_recruitment_access(string $roleCode): void
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = $this->user();
        $staff->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        $this->asStaff($staff)->getJson('/api/v1/recruitment/applications')->assertOk();
        $this->postJson('/api/v1/recruitment/exams', self::examInput())->assertCreated();

        $this->assertDatabaseCount('recruitment_exams', 1);
    }

    public function test_recruitment_permission_from_inactive_role_does_not_allow_staff_access(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = $this->user();
        $role = Role::query()->where('code', 'hr_staff')->firstOrFail();
        $staff->roles()->attach($role);
        $role->update(['is_active' => false]);

        $this->asStaff($staff)->getJson('/api/v1/recruitment/applications')->assertForbidden();
        $this->postJson('/api/v1/recruitment/exams', self::examInput())->assertForbidden();

        $this->assertDatabaseCount('recruitment_exams', 0);
    }

    public function test_inactive_staff_account_receives_403_even_with_recruitment_permissions(): void
    {
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $staff->update(['is_active' => false]);

        $this->asStaff($staff)->getJson('/api/v1/recruitment/applications')->assertForbidden();
        $this->postJson('/api/v1/recruitment/exams', self::examInput())->assertForbidden();

        $this->assertDatabaseCount('recruitment_exams', 0);
    }

    public function test_approval_uses_server_reviewer_and_becomes_visible_to_the_applicant(): void
    {
        $this->travelTo('2026-10-06 09:30:00');
        $candidate = Applicant::factory()->create();
        $application = $this->createJobApplication($candidate);
        $staff = $this->user('recruitment.manage');

        $response = $this->asStaff($staff)->postJson('/api/v1/recruitment/applications/'.$application->id.'/approve', [
            'remarks' => 'Proceed to the exam.', 'reviewed_by' => 999999, 'reviewed_at' => '2000-01-01', 'start_date' => '2026-01-01',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.start_date', null);
        $this->assertDatabaseHas('job_applications', [
            'id' => $application->id, 'status' => 'approved', 'reviewed_by' => $staff->id,
            'reviewed_at' => '2026-10-06 09:30:00', 'remarks' => 'Proceed to the exam.',
        ]);
        $this->asApplicant($candidate)->getJson('/api/v1/applicant/applications/'.$application->id)
            ->assertOk()->assertJsonPath('data.status', 'approved');
    }

    #[TestWith(['approved'])]
    #[TestWith(['rejected'])]
    #[TestWith(['hired'])]
    public function test_non_pending_approval_returns_409_without_changing_status(string $status): void
    {
        $candidate = Applicant::factory()->create();
        $application = $this->createJobApplication($candidate);
        $application->update(['status' => $status]);

        $this->asStaff($this->user('recruitment.manage'))->postJson('/api/v1/recruitment/applications/'.$application->id.'/approve', [])
            ->assertConflict();

        $this->assertSame($status, $application->fresh()->status);
    }

    public function test_approved_application_can_be_rejected_after_review(): void
    {
        [$staff, $candidate, $application] = $this->approvedApplication();

        $this->asStaff($staff)->postJson('/api/v1/recruitment/applications/'.$application->id.'/reject', [
            'remarks' => 'The vacancy has been filled.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertSame('rejected', $application->fresh()->status);
        $this->asApplicant($candidate)->getJson('/api/v1/applicant/applications/'.$application->id)
            ->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_exam_creation_generates_question_ids_and_maximum_score(): void
    {
        $staff = $this->staff();

        $response = $this->asStaff($staff)->postJson('/api/v1/recruitment/exams', self::examInput(self::mixedQuestions()) + [
            'created_by' => 999999, 'max_score' => 999999,
        ]);

        $response->assertCreated()->assertJsonPath('data.max_score', 10)->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.questions.0.correct_option', 1);
        $this->assertTrue(Str::isUuid($response->json('data.questions.0.id')));
        $this->assertTrue(Str::isUuid($response->json('data.questions.1.id')));
        $this->assertNotSame($response->json('data.questions.0.id'), $response->json('data.questions.1.id'));
        $this->assertDatabaseHas('recruitment_exams', ['id' => $response->json('data.id'), 'max_score' => 10, 'created_by' => $staff->id]);
    }

    public function test_invalid_exam_definition_returns_422_without_persisting(): void
    {
        $payload = self::examInput();
        $payload['questions'][0]['correct_option'] = 9;

        $this->asStaff($this->staff())->postJson('/api/v1/recruitment/exams', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('questions.0.correct_option');

        $this->assertDatabaseCount('recruitment_exams', 0);
    }

    public function test_assignment_snapshots_the_exam_and_never_discloses_answer_keys_to_applicants(): void
    {
        [$staff, $candidate, $application] = $this->approvedApplication();
        $exam = $this->createExam($staff, self::mixedQuestions());
        $attempt = $this->assignExam($staff, $application, $exam);
        $original = $exam->questions;
        $edited = $original;
        $edited[0]['prompt'] = 'Changed after assignment';
        $edited[0]['correct_option'] = 0;
        $edited[0]['points'] = 99;
        $exam->update(['questions' => $edited, 'max_score' => 106, 'passing_percentage' => 99]);

        $response = $this->asApplicant($candidate)->getJson('/api/v1/applicant/exams/'.$attempt['id']);

        $response->assertOk()->assertJsonPath('data.questions.0.prompt', 'Which number is even?')
            ->assertJsonPath('data.questions.0.points', 3)->assertJsonPath('data.max_score', 10)
            ->assertJsonPath('data.passing_percentage', 70)
            ->assertJsonPath('data.status', 'assigned')->assertJsonPath('data.score', null)
            ->assertJsonPath('data.percentage', null)->assertJsonPath('data.passed', null)
            ->assertJsonPath('data.grades', null)->assertJsonPath('data.abilities.submit', true)
            ->assertJsonMissingPath('data.automatic_score');
        $this->assertNoAnswerKeys($response->json());
        $applicationResponse = $this->getJson('/api/v1/applicant/applications/'.$application->id)->assertOk();
        $this->assertNoAnswerKeys($applicationResponse->json());
    }

    public function test_unapproved_application_and_inactive_exam_assignments_return_409(): void
    {
        $candidate = Applicant::factory()->create();
        $application = $this->createJobApplication($candidate);
        $staff = $this->staff();
        $exam = $this->createExam($staff);

        $this->asStaff($staff)->postJson('/api/v1/recruitment/applications/'.$application->id.'/exams', ['recruitment_exam_id' => $exam->id])
            ->assertConflict();
        $this->postJson('/api/v1/recruitment/applications/'.$application->id.'/approve', [])->assertOk();
        $exam->update(['is_active' => false]);
        $this->postJson('/api/v1/recruitment/applications/'.$application->id.'/exams', ['recruitment_exam_id' => $exam->id])
            ->assertConflict();

        $this->assertDatabaseCount('applicant_exam_attempts', 0);
    }

    public function test_duplicate_exam_assignment_returns_409_and_keeps_one_attempt(): void
    {
        [$staff, , $application] = $this->approvedApplication();
        $exam = $this->createExam($staff);
        $attempt = $this->assignExam($staff, $application, $exam);

        $this->asStaff($staff)->postJson('/api/v1/recruitment/applications/'.$application->id.'/exams', ['recruitment_exam_id' => $exam->id])
            ->assertConflict();

        $this->assertDatabaseCount('applicant_exam_attempts', 1);
        $this->assertDatabaseHas('applicant_exam_attempts', ['id' => $attempt['id'], 'status' => 'assigned']);
    }

    public function test_foreign_applicant_cannot_view_or_submit_an_assigned_exam_and_receives_404(): void
    {
        [$staff, , $application] = $this->approvedApplication();
        $attempt = $this->assignExam($staff, $application, $this->createExam($staff));
        $foreign = Applicant::factory()->create();

        $this->asApplicant($foreign)->getJson('/api/v1/applicant/exams/'.$attempt['id'])->assertNotFound();
        $this->postJson('/api/v1/applicant/exams/'.$attempt['id'].'/submit', ['answers' => self::answers($attempt, ['1'])])
            ->assertNotFound();

        $this->assertDatabaseHas('applicant_exam_attempts', ['id' => $attempt['id'], 'status' => 'assigned', 'submitted_at' => null]);
    }

    public function test_pure_multiple_choice_exam_is_scored_automatically_from_snapshot_without_client_scores(): void
    {
        $this->travelTo('2026-10-06 10:00:00');
        [$staff, $candidate, $application] = $this->approvedApplication();
        $questions = [
            self::multipleChoiceQuestion(),
            ['type' => 'multiple_choice', 'prompt' => 'Select the smallest number.', 'points' => 7,
                'options' => ['2', '8', '9'], 'correct_option' => 0],
        ];
        $exam = $this->createExam($staff, $questions);
        $attempt = $this->assignExam($staff, $application, $exam);
        $edited = $exam->questions;
        $edited[0]['correct_option'] = 0;
        $exam->update(['questions' => $edited]);

        $response = $this->asApplicant($candidate)->postJson('/api/v1/applicant/exams/'.$attempt['id'].'/submit', [
            'answers' => self::answers($attempt, ['1', '2']),
            'score' => 10, 'percentage' => 100, 'passed' => true,
            'automatic_score' => 10, 'grades' => [['score' => 10]], 'status' => 'graded',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'graded')->assertJsonPath('data.score', 3)
            ->assertJsonPath('data.percentage', 30)->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.grades.0.score', 3)->assertJsonPath('data.grades.0.automatic', true)
            ->assertJsonPath('data.grades.1.score', 0)->assertJsonPath('data.abilities.submit', false);
        $this->assertNoAnswerKeys($response->json());
        $this->assertDatabaseHas('applicant_exam_attempts', ['id' => $attempt['id'], 'status' => 'graded', 'score' => 3]);
        $this->assertNotNull(ApplicantExamAttempt::query()->findOrFail($attempt['id'])->graded_at);
        $this->getJson('/api/v1/applicant/exams/'.$attempt['id'])->assertOk()->assertJsonPath('data.score', 3);
    }

    public function test_mixed_exam_waits_for_manual_grading_then_exposes_final_result_and_feedback(): void
    {
        [$staff, $candidate, $application] = $this->approvedApplication();
        $attempt = $this->assignExam($staff, $application, $this->createExam($staff, self::mixedQuestions()));

        $submitted = $this->asApplicant($candidate)->postJson('/api/v1/applicant/exams/'.$attempt['id'].'/submit', [
            'answers' => self::answers($attempt, ['1', 'A clear written response.']),
            'grades' => [['score' => 7]], 'score' => 10, 'passed' => true,
        ]);

        $submitted->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.score', null)
            ->assertJsonPath('data.percentage', null)->assertJsonPath('data.passed', null)->assertJsonPath('data.grades', null)
            ->assertJsonMissingPath('data.automatic_score');
        $this->assertNoAnswerKeys($submitted->json());
        $this->assertDatabaseHas('applicant_exam_attempts', ['id' => $attempt['id'], 'status' => 'submitted', 'score' => null]);

        $graded = $this->asStaff($staff)->postJson('/api/v1/recruitment/attempts/'.$attempt['id'].'/grade', [
            'grades' => [['question_id' => $attempt['questions'][1]['id'], 'score' => 4, 'feedback' => 'Good explanation; add an example.']],
            'automatic_score' => 999, 'score' => 999, 'passed' => false, 'graded_by' => 999999,
        ]);

        $graded->assertOk()->assertJsonPath('data.status', 'graded')->assertJsonPath('data.score', 7)
            ->assertJsonPath('data.percentage', 70)->assertJsonPath('data.passed', true);
        $this->assertDatabaseHas('applicant_exam_attempts', ['id' => $attempt['id'], 'status' => 'graded', 'score' => 7, 'graded_by' => $staff->id]);
        $result = $this->asApplicant($candidate)->getJson('/api/v1/applicant/exams/'.$attempt['id'])->assertOk()
            ->assertJsonPath('data.grades.1.feedback', 'Good explanation; add an example.')
            ->assertJsonPath('data.grades.1.automatic', false)->assertJsonPath('data.score', 7);
        $this->assertNoAnswerKeys($result->json());
        $this->assertNoAnswerKeys($this->getJson('/api/v1/applicant/applications/'.$application->id)->assertOk()->json());
    }

    public function test_written_only_exam_accepts_fractional_manual_scores(): void
    {
        [$staff, $candidate, $application] = $this->approvedApplication();
        $attempt = $this->assignExam($staff, $application, $this->createExam($staff, [
            ['type' => 'written', 'prompt' => 'Explain confidentiality.', 'points' => 10],
        ]));
        $this->asApplicant($candidate)->postJson('/api/v1/applicant/exams/'.$attempt['id'].'/submit', [
            'answers' => self::answers($attempt, ['Protect private records.']),
        ])->assertOk()->assertJsonPath('data.status', 'submitted');

        $this->asStaff($staff)->postJson('/api/v1/recruitment/attempts/'.$attempt['id'].'/grade', [
            'grades' => [['question_id' => $attempt['questions'][0]['id'], 'score' => 7.5]],
        ])->assertOk()->assertJsonPath('data.score', 7.5)->assertJsonPath('data.percentage', 75)
            ->assertJsonPath('data.passed', true);

        $this->assertDatabaseHas('applicant_exam_attempts', ['id' => $attempt['id'], 'score' => 7.5, 'status' => 'graded']);
    }

    #[DataProvider('invalidAnswerSets')]
    public function test_invalid_answer_set_returns_422_and_keeps_attempt_unsubmitted(string $kind, string $error): void
    {
        [$staff, $candidate, $application] = $this->approvedApplication();
        $attempt = $this->assignExam($staff, $application, $this->createExam($staff, self::mixedQuestions()));
        $answers = self::answers($attempt, ['1', 'Written answer']);
        $answers = match ($kind) {
            'missing' => [$answers[0]],
            'duplicate' => [$answers[0], $answers[0]],
            'unknown' => [['question_id' => (string) Str::uuid(), 'answer' => '1'], $answers[1]],
            'out-of-range' => [array_replace($answers[0], ['answer' => '9']), $answers[1]],
            'negative' => [array_replace($answers[0], ['answer' => '-1']), $answers[1]],
            'text-choice' => [array_replace($answers[0], ['answer' => 'Two']), $answers[1]],
            'array-answer' => [array_replace($answers[0], ['answer' => ['1']]), $answers[1]],
            'empty-written' => [$answers[0], array_replace($answers[1], ['answer' => ''])],
        };

        $this->asApplicant($candidate)->postJson('/api/v1/applicant/exams/'.$attempt['id'].'/submit', ['answers' => $answers])
            ->assertUnprocessable()->assertJsonValidationErrors($error);

        $this->assertDatabaseHas('applicant_exam_attempts', ['id' => $attempt['id'], 'status' => 'assigned', 'submitted_at' => null]);
    }

    public static function invalidAnswerSets(): array
    {
        return [
            'missing question' => ['missing', 'answers'], 'duplicate question' => ['duplicate', 'answers'],
            'unknown question' => ['unknown', 'answers'], 'out of range choice' => ['out-of-range', 'answers'],
            'negative choice' => ['negative', 'answers'], 'choice label instead of index' => ['text-choice', 'answers'],
            'array answer' => ['array-answer', 'answers.0.answer'], 'blank written answer' => ['empty-written', 'answers.1.answer'],
        ];
    }

    public function test_duplicate_submission_returns_409_and_preserves_the_original_answers_and_grade(): void
    {
        [$staff, $candidate, $application] = $this->approvedApplication();
        $attempt = $this->assignExam($staff, $application, $this->createExam($staff));
        $this->asApplicant($candidate)->postJson('/api/v1/applicant/exams/'.$attempt['id'].'/submit', [
            'answers' => self::answers($attempt, ['1']),
        ])->assertOk()->assertJsonPath('data.score', 3);

        $this->postJson('/api/v1/applicant/exams/'.$attempt['id'].'/submit', ['answers' => self::answers($attempt, ['0'])])
            ->assertConflict();

        $result = $this->getJson('/api/v1/applicant/exams/'.$attempt['id'])->assertOk()->assertJsonPath('data.score', 3)
            ->assertJsonPath('data.answers.0.answer', '1');
        $this->assertNoAnswerKeys($result->json());
    }

    public function test_rejected_application_keeps_exam_viewable_but_returns_409_for_submission(): void
    {
        [$staff, $candidate, $application] = $this->approvedApplication();
        $attempt = $this->assignExam($staff, $application, $this->createExam($staff));
        $this->asStaff($staff)->postJson('/api/v1/recruitment/applications/'.$application->id.'/reject', [])->assertOk();

        $this->asApplicant($candidate)->getJson('/api/v1/applicant/exams/'.$attempt['id'])->assertOk()
            ->assertJsonPath('data.abilities.submit', false);
        $this->postJson('/api/v1/applicant/exams/'.$attempt['id'].'/submit', ['answers' => self::answers($attempt, ['1'])])
            ->assertConflict();

        $this->assertDatabaseHas('applicant_exam_attempts', ['id' => $attempt['id'], 'status' => 'assigned', 'submitted_at' => null]);
    }

    #[DataProvider('invalidGradeSets')]
    public function test_invalid_manual_grade_returns_422_without_publishing_a_result(string $kind, string $error): void
    {
        [$staff, $candidate, $application] = $this->approvedApplication();
        $attempt = $this->assignExam($staff, $application, $this->createExam($staff, self::mixedQuestions()));
        $this->asApplicant($candidate)->postJson('/api/v1/applicant/exams/'.$attempt['id'].'/submit', [
            'answers' => self::answers($attempt, ['1', 'Written response.']),
        ])->assertOk();
        $valid = ['question_id' => $attempt['questions'][1]['id'], 'score' => 4];
        $grades = match ($kind) {
            'missing' => [],
            'duplicate' => [$valid, $valid],
            'unknown' => [array_replace($valid, ['question_id' => (string) Str::uuid()])],
            'automatic-question' => [array_replace($valid, ['question_id' => $attempt['questions'][0]['id']])],
            'negative' => [array_replace($valid, ['score' => -0.5])],
            'exceeds-points' => [array_replace($valid, ['score' => 7.01])],
        };

        $this->asStaff($staff)->postJson('/api/v1/recruitment/attempts/'.$attempt['id'].'/grade', ['grades' => $grades])
            ->assertUnprocessable()->assertJsonValidationErrors($error);

        $this->assertDatabaseHas('applicant_exam_attempts', ['id' => $attempt['id'], 'status' => 'submitted', 'score' => null, 'graded_at' => null]);
        $this->asApplicant($candidate)->getJson('/api/v1/applicant/exams/'.$attempt['id'])->assertOk()
            ->assertJsonPath('data.grades', null)->assertJsonPath('data.score', null);
    }

    public static function invalidGradeSets(): array
    {
        return [
            'no written grades' => ['missing', 'grades'], 'duplicate written grade' => ['duplicate', 'grades'],
            'unknown question' => ['unknown', 'grades'], 'override automatic score' => ['automatic-question', 'grades'],
            'negative score' => ['negative', 'grades.0.score'], 'score above question points' => ['exceeds-points', 'grades'],
        ];
    }

    public function test_duplicate_manual_grading_returns_409_and_preserves_published_feedback(): void
    {
        [$staff, $candidate, $application] = $this->approvedApplication();
        $attempt = $this->assignExam($staff, $application, $this->createExam($staff, self::mixedQuestions()));
        $this->asApplicant($candidate)->postJson('/api/v1/applicant/exams/'.$attempt['id'].'/submit', [
            'answers' => self::answers($attempt, ['1', 'Written response.']),
        ])->assertOk();
        $payload = ['grades' => [['question_id' => $attempt['questions'][1]['id'], 'score' => 4, 'feedback' => 'Published feedback']]];
        $this->asStaff($staff)->postJson('/api/v1/recruitment/attempts/'.$attempt['id'].'/grade', $payload)->assertOk();
        $payload['grades'][0]['score'] = 7;
        $payload['grades'][0]['feedback'] = 'Replacement';

        $this->postJson('/api/v1/recruitment/attempts/'.$attempt['id'].'/grade', $payload)->assertConflict();

        $this->asApplicant($candidate)->getJson('/api/v1/applicant/exams/'.$attempt['id'])->assertOk()
            ->assertJsonPath('data.score', 7)->assertJsonPath('data.grades.1.feedback', 'Published feedback');
    }

    public function test_grading_before_submission_returns_409(): void
    {
        [$staff, , $application] = $this->approvedApplication();
        $attempt = $this->assignExam($staff, $application, $this->createExam($staff, self::mixedQuestions()));

        $this->asStaff($staff)->postJson('/api/v1/recruitment/attempts/'.$attempt['id'].'/grade', [
            'grades' => [['question_id' => $attempt['questions'][1]['id'], 'score' => 4]],
        ])->assertConflict();

        $this->assertDatabaseHas('applicant_exam_attempts', ['id' => $attempt['id'], 'status' => 'assigned', 'graded_at' => null]);
    }

    public function test_manage_permission_does_not_grant_manual_grading(): void
    {
        [$staff, $candidate, $application] = $this->approvedApplication();
        $attempt = $this->assignExam($staff, $application, $this->createExam($staff, self::mixedQuestions()));
        $this->asApplicant($candidate)->postJson('/api/v1/applicant/exams/'.$attempt['id'].'/submit', [
            'answers' => self::answers($attempt, ['1', 'Written response.']),
        ])->assertOk();
        $manager = $this->user('recruitment.view', 'recruitment.manage');

        $this->asStaff($manager)->postJson('/api/v1/recruitment/attempts/'.$attempt['id'].'/grade', [
            'grades' => [['question_id' => $attempt['questions'][1]['id'], 'score' => 7]],
        ])->assertForbidden();

        $this->assertDatabaseHas('applicant_exam_attempts', ['id' => $attempt['id'], 'status' => 'submitted', 'score' => null]);
    }

    public function test_hiring_requires_all_assigned_exams_to_be_graded(): void
    {
        [$staff, $candidate, $application] = $this->approvedApplication();
        $first = $this->assignExam($staff, $application, $this->createExam($staff));
        $second = $this->assignExam($staff, $application, $this->createExam($staff, self::mixedQuestions()));
        $this->asApplicant($candidate)->postJson('/api/v1/applicant/exams/'.$first['id'].'/submit', ['answers' => self::answers($first, ['1'])])
            ->assertOk();
        $this->postJson('/api/v1/applicant/exams/'.$second['id'].'/submit', ['answers' => self::answers($second, ['1', 'Written response.'])])
            ->assertOk();

        $this->asStaff($staff)->postJson('/api/v1/recruitment/applications/'.$application->id.'/hire', ['start_date' => '2026-10-20'])
            ->assertConflict();

        $this->assertSame('approved', $application->fresh()->status);
        $this->assertNull($application->fresh()->start_date);
    }

    public function test_hired_applicant_sees_start_date_and_grade_without_creating_employee_or_user_records(): void
    {
        $this->travelTo('2026-10-06 11:00:00');
        [$staff, $candidate, $application] = $this->approvedApplication();
        $attempt = $this->assignExam($staff, $application, $this->createExam($staff));
        $this->asApplicant($candidate)->postJson('/api/v1/applicant/exams/'.$attempt['id'].'/submit', ['answers' => self::answers($attempt, ['1'])])
            ->assertOk();
        $usersBefore = User::query()->count();
        $employeesBefore = Employee::query()->count();

        $response = $this->asStaff($staff)->postJson('/api/v1/recruitment/applications/'.$application->id.'/hire', [
            'start_date' => '2026-10-20', 'remarks' => 'Report at 8 AM.', 'employee_number' => 'INJECTED-001',
            'hired_by' => 999999, 'hired_at' => '2000-01-01',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'hired')->assertJsonPath('data.start_date', '2026-10-20');
        $this->assertDatabaseHas('job_applications', [
            'id' => $application->id, 'status' => 'hired', 'hired_by' => $staff->id, 'hired_at' => '2026-10-06 11:00:00',
        ]);
        $this->assertSame('2026-10-20', $application->fresh()->start_date->format('Y-m-d'));
        $this->assertSame($usersBefore, User::query()->count());
        $this->assertSame($employeesBefore, Employee::query()->count());
        $own = $this->asApplicant($candidate)->getJson('/api/v1/applicant/applications/'.$application->id)->assertOk()
            ->assertJsonPath('data.status', 'hired')->assertJsonPath('data.start_date', '2026-10-20');
        $this->assertNoAnswerKeys($own->json());
        $this->getJson('/api/v1/applicant/exams/'.$attempt['id'])->assertOk()->assertJsonPath('data.score', 3);
    }

    public function test_approved_applicant_without_assigned_exams_can_be_hired(): void
    {
        [$staff, $candidate, $application] = $this->approvedApplication();

        $this->asStaff($staff)->postJson('/api/v1/recruitment/applications/'.$application->id.'/hire', ['start_date' => '2026-10-20'])
            ->assertOk()->assertJsonPath('data.status', 'hired')->assertJsonPath('data.start_date', '2026-10-20');

        $this->asApplicant($candidate)->getJson('/api/v1/applicant/applications/'.$application->id)->assertOk()
            ->assertJsonPath('data.status', 'hired');
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_hiring_without_start_date_returns_422(): void
    {
        [$staff, , $application] = $this->approvedApplication();

        $this->asStaff($staff)->postJson('/api/v1/recruitment/applications/'.$application->id.'/hire', [])
            ->assertUnprocessable()->assertJsonValidationErrors('start_date');

        $this->assertSame('approved', $application->fresh()->status);
        $this->assertNull($application->fresh()->start_date);
    }

    #[TestWith(['rejected', 'reject'])]
    #[TestWith(['hired', 'reject'])]
    #[TestWith(['rejected', 'hire'])]
    #[TestWith(['hired', 'hire'])]
    public function test_terminal_application_writes_return_409_and_preserve_status(string $status, string $action): void
    {
        [$staff, , $application] = $this->approvedApplication();
        $application->update(['status' => $status]);

        $this->asStaff($staff)->postJson('/api/v1/recruitment/applications/'.$application->id.'/'.$action, [
            'start_date' => '2026-10-20', 'remarks' => 'Replacement',
        ])->assertConflict();

        $this->assertSame($status, $application->fresh()->status);
        $this->assertNull($application->fresh()->start_date);
    }

    private function asApplicant(Applicant $candidate): static
    {
        Auth::forgetGuards();

        return $this->actingAs($candidate, 'applicant');
    }

    private function asStaff(User $staff): static
    {
        Auth::forgetGuards();

        return $this->actingAs($staff, 'web');
    }

    private function staff(): User
    {
        return $this->user('recruitment.view', 'recruitment.manage', 'recruitment.grade');
    }

    private function createJobApplication(Applicant $candidate): JobApplication
    {
        $response = $this->asApplicant($candidate)->postJson('/api/v1/applicant/applications', self::applicationInput())
            ->assertCreated();

        return JobApplication::query()->findOrFail($response->json('data.id'));
    }

    /**
     * @return array{User, Applicant, JobApplication}
     */
    private function approvedApplication(): array
    {
        $staff = $this->staff();
        $candidate = Applicant::factory()->create();
        $application = $this->createJobApplication($candidate);
        $this->asStaff($staff)->postJson('/api/v1/recruitment/applications/'.$application->id.'/approve', [])
            ->assertOk();

        return [$staff, $candidate, $application->fresh()];
    }

    /**
     * @param  list<array<string, mixed>>|null  $questions
     */
    private function createExam(User $staff, ?array $questions = null): RecruitmentExam
    {
        $response = $this->asStaff($staff)->postJson('/api/v1/recruitment/exams', self::examInput($questions))->assertCreated();

        return RecruitmentExam::query()->findOrFail($response->json('data.id'));
    }

    /**
     * @return array<string, mixed>
     */
    private function assignExam(User $staff, JobApplication $application, RecruitmentExam $exam): array
    {
        return $this->asStaff($staff)->postJson('/api/v1/recruitment/applications/'.$application->id.'/exams', [
            'recruitment_exam_id' => $exam->id,
        ])->assertCreated()->json('data');
    }

    /**
     * @return array<string, mixed>
     */
    private static function applicationInput(): array
    {
        return [
            'job_opening_id' => JobOpening::factory()->create(['title' => 'Administrative Assistant'])->id,
            'cover_letter' => 'I would like to join your team.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>|null  $questions
     * @return array<string, mixed>
     */
    private static function examInput(?array $questions = null): array
    {
        return [
            'title' => 'Applicant Assessment', 'instructions' => 'Answer every question.',
            'passing_percentage' => 70, 'questions' => $questions ?? [self::multipleChoiceQuestion()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function multipleChoiceQuestion(): array
    {
        return [
            'type' => 'multiple_choice', 'prompt' => 'Which number is even?', 'points' => 3,
            'options' => ['One', 'Two', 'Three'], 'correct_option' => 1,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function mixedQuestions(): array
    {
        return [self::multipleChoiceQuestion(), ['type' => 'written', 'prompt' => 'Explain good record keeping.', 'points' => 7]];
    }

    /**
     * @param  array<string, mixed>  $attempt
     * @param  list<string>  $values
     * @return list<array{question_id: string, answer: string}>
     */
    private static function answers(array $attempt, array $values): array
    {
        return array_map(
            static fn (array $question, string $answer): array => ['question_id' => $question['id'], 'answer' => $answer],
            $attempt['questions'], $values,
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function assertNoAnswerKeys(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->assertNotContains($key, ['correct_option', 'answer_key', 'correct_answer', 'password', 'remember_token']);

            if (is_array($value)) {
                $this->assertNoAnswerKeys($value);
            }
        }

    }
}
