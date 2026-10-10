<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\ApplicantExamAttempt;
use App\Models\JobApplication;
use App\Models\Permission;
use App\Models\RecruitmentExam;
use App\Models\Role;
use Carbon\CarbonInterface;
use Database\Seeders\RecruitmentPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class RecruitmentFoundationTest extends FormRequestTestCase
{
    public function test_applicant_credentials_are_hashed_and_hidden_without_creating_employee_identity(): void
    {
        $applicant = Applicant::factory()->create([
            'email' => 'external-applicant@example.test',
            'password' => 'fixture-applicant-password',
            'last_login_at' => '2026-10-06 10:00:00',
        ])->refresh();

        $this->assertTrue(Hash::check('fixture-applicant-password', $applicant->password));
        $this->assertTrue($applicant->is_active);
        $this->assertInstanceOf(CarbonInterface::class, $applicant->last_login_at);
        $this->assertSame('2026-10-06 10:00:00', $applicant->last_login_at->format('Y-m-d H:i:s'));
        $keys = array_keys($applicant->toArray());
        sort($keys);
        $this->assertSame([
            'created_at', 'email', 'first_name', 'id', 'is_active', 'last_login_at',
            'last_name', 'middle_name', 'mobile_number', 'updated_at', 'uuid',
        ], $keys);
        $this->assertDatabaseCount('applicants', 1);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('employees', 0);
    }

    #[DataProvider('uniqueApplicantIdentifiers')]
    public function test_applicant_identity_duplicates_are_rejected_by_the_schema(string $field): void
    {
        $applicant = Applicant::factory()->create();

        $this->expectException(QueryException::class);

        Applicant::factory()->create([$field => $applicant->getAttribute($field)]);
    }

    public function test_typed_relationships_resolve_the_application_exam_and_staff_owners(): void
    {
        $staff = $this->user();
        $applicant = Applicant::factory()->create();
        $application = JobApplication::factory()->for($applicant)->approved()->create([
            'reviewed_by' => $staff->id,
            'hired_by' => $staff->id,
        ]);
        $exam = RecruitmentExam::factory()->create(['created_by' => $staff->id]);
        $attempt = ApplicantExamAttempt::factory()->for($application, 'application')->for($exam, 'exam')->create([
            'assigned_by' => $staff->id,
            'graded_by' => $staff->id,
        ]);

        $this->assertTrue($applicant->applications()->sole()->is($application));
        $this->assertTrue($application->applicant->is($applicant));
        $this->assertTrue($application->attempts()->sole()->is($attempt));
        $this->assertTrue($application->reviewer->is($staff));
        $this->assertTrue($application->hirer->is($staff));
        $this->assertTrue($exam->attempts()->sole()->is($attempt));
        $this->assertTrue($exam->creator->is($staff));
        $this->assertTrue($attempt->application->is($application));
        $this->assertTrue($attempt->exam->is($exam));
        $this->assertTrue($attempt->assigner->is($staff));
        $this->assertTrue($attempt->grader->is($staff));
    }

    public function test_assigned_exam_snapshot_survives_changes_to_the_source_exam(): void
    {
        $exam = RecruitmentExam::factory()->create();
        $attempt = ApplicantExamAttempt::factory()->for($exam, 'exam')->create();
        $snapshot = $attempt->questions;

        $exam->update(['questions' => [], 'max_score' => 100, 'passing_percentage' => 80]);

        $attempt->refresh();
        $this->assertSame($snapshot, $attempt->questions);
        $this->assertSame(15, $attempt->max_score);
        $this->assertSame('60.00', $attempt->passing_percentage);
        $this->assertSame(0, $attempt->questions[0]['correct_option']);
        $this->assertArrayNotHasKey('questions', $attempt->toArray());
        $this->assertArrayNotHasKey('questions', $exam->toArray());
    }

    public function test_duplicate_assignment_of_the_same_exam_to_an_application_is_rejected(): void
    {
        $attempt = ApplicantExamAttempt::factory()->create();

        $this->expectException(QueryException::class);

        ApplicantExamAttempt::factory()->create([
            'job_application_id' => $attempt->job_application_id,
            'recruitment_exam_id' => $attempt->recruitment_exam_id,
        ]);
    }

    public function test_the_same_exam_can_be_assigned_to_different_applicants(): void
    {
        $exam = RecruitmentExam::factory()->create();
        $first = ApplicantExamAttempt::factory()->for($exam, 'exam')->create();
        $second = ApplicantExamAttempt::factory()->for($exam, 'exam')->create();

        $this->assertNotSame($first->job_application_id, $second->job_application_id);
        $this->assertDatabaseCount('applicant_exam_attempts', 2);
        $this->assertDatabaseCount('recruitment_exams', 1);
        $this->assertDatabaseCount('applicants', 2);
    }

    #[DataProvider('historicalParents')]
    public function test_historical_parents_cannot_be_deleted_while_their_application_exam_records_exist(string $relationship): void
    {
        $attempt = ApplicantExamAttempt::factory()->create();
        $parent = match ($relationship) {
            'applicant' => $attempt->application->applicant,
            'application' => $attempt->application,
            'exam' => $attempt->exam,
        };

        try {
            $parent->delete();
        } catch (QueryException) {
            $this->assertModelExists($parent);
            $this->assertModelExists($attempt);

            return;
        }

        $this->fail('Expected historical recruitment records to prevent deletion of their parent.');
    }

    public function test_staff_deletion_keeps_recruitment_history_with_nullable_staff_references(): void
    {
        $staff = $this->user();
        $application = JobApplication::factory()->create(['reviewed_by' => $staff->id, 'hired_by' => $staff->id]);
        $exam = RecruitmentExam::factory()->create(['created_by' => $staff->id]);
        $attempt = ApplicantExamAttempt::factory()->for($application, 'application')->for($exam, 'exam')->create([
            'assigned_by' => $staff->id,
            'graded_by' => $staff->id,
        ]);

        $staff->delete();

        $this->assertModelExists($application);
        $this->assertModelExists($exam);
        $this->assertModelExists($attempt);
        $this->assertNull($application->refresh()->reviewed_by);
        $this->assertNull($application->hired_by);
        $this->assertNull($exam->refresh()->created_by);
        $this->assertNull($attempt->refresh()->assigned_by);
        $this->assertNull($attempt->graded_by);
    }

    public function test_attempt_results_round_trip_as_decimal_scores_arrays_dates_and_nullable_boolean(): void
    {
        $attempt = ApplicantExamAttempt::factory()->create();
        $this->assertNull($attempt->passed);
        $this->assertNull($attempt->score);
        $this->assertSame('0.00', $attempt->automatic_score);
        $questionId = $attempt->questions[1]['id'];

        $attempt->update([
            'answers' => [$questionId => 'A written answer.'],
            'grades' => [$questionId => ['score' => 6.5, 'feedback' => 'Clear explanation.']],
            'automatic_score' => 5,
            'score' => 11.5,
            'percentage' => 76.67,
            'passed' => true,
            'submitted_at' => '2026-10-06 10:00:00',
            'graded_at' => '2026-10-06 11:00:00',
        ]);

        $attempt->refresh();
        $this->assertSame([$questionId => 'A written answer.'], $attempt->answers);
        $this->assertSame([$questionId => ['score' => 6.5, 'feedback' => 'Clear explanation.']], $attempt->grades);
        $this->assertSame('5.00', $attempt->automatic_score);
        $this->assertSame('11.50', $attempt->score);
        $this->assertSame('76.67', $attempt->percentage);
        $this->assertTrue($attempt->passed);
        $this->assertInstanceOf(CarbonInterface::class, $attempt->submitted_at);
        $this->assertSame('2026-10-06 11:00:00', $attempt->graded_at->format('Y-m-d H:i:s'));
    }

    public function test_hiring_details_round_trip_without_creating_an_employee_or_internal_account(): void
    {
        $application = JobApplication::factory()->create();

        $application->update([
            'status' => 'hired',
            'reviewed_at' => '2026-10-06 08:00:00',
            'hired_at' => '2026-10-06 09:00:00',
            'start_date' => '2026-10-20',
        ]);

        $application->refresh();
        $this->assertSame('hired', $application->status);
        $this->assertSame('2026-10-20', $application->start_date->toDateString());
        $this->assertSame('2026-10-06 08:00:00', $application->reviewed_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-06 09:00:00', $application->hired_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('employees', 0);
    }

    #[DataProvider('recruitmentRoles')]
    public function test_full_permission_seeding_grants_recruitment_only_to_super_admin_and_hr_roles(string $roleCode, bool $allowed): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = $this->user();
        $role = Role::query()->where('code', $roleCode)->firstOrFail();
        $user->roles()->attach($role);

        $this->seed(RolePermissionSeeder::class);

        foreach (['recruitment.view', 'recruitment.manage', 'recruitment.grade'] as $code) {
            $this->assertSame($allowed, $user->hasPermission($code));
        }
        $this->assertDatabaseCount('roles', 7);
        $this->assertDatabaseCount('applicants', 0);
        $this->assertDatabaseCount('recruitment_exams', 0);
    }

    public function test_scoped_permission_seeding_is_repeatable_and_preserves_unrelated_roles_and_grants(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::query()->where('code', 'hr_admin')->firstOrFail();
        $customPermission = Permission::query()->create(['code' => 'fixture.custom', 'name' => 'Fixture Custom', 'is_active' => false]);
        $role->permissions()->attach($customPermission);
        $permissionsBefore = Permission::query()->where('code', 'not like', 'recruitment.%')->orderBy('id')->get()->map->getAttributes()->all();
        $rolesBefore = Role::query()->orderBy('id')->get()->map->getAttributes()->all();
        $this->seed(RecruitmentPermissionSeeder::class);
        $ids = Permission::query()->where('code', 'like', 'recruitment.%')->orderBy('code')->pluck('id', 'code')->all();
        $pivotCount = $role->permissions()->count();

        $this->seed(RecruitmentPermissionSeeder::class);

        $this->assertSame($ids, Permission::query()->where('code', 'like', 'recruitment.%')->orderBy('code')->pluck('id', 'code')->all());
        $this->assertSame($pivotCount, $role->permissions()->count());
        $this->assertSame($permissionsBefore, Permission::query()->where('code', 'not like', 'recruitment.%')->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($rolesBefore, Role::query()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertTrue($role->permissions()->whereKey($customPermission->id)->exists());
        $this->assertDatabaseCount('roles', 7);
        $this->assertDatabaseCount('applicants', 0);
        $this->assertDatabaseCount('recruitment_exams', 0);
    }

    public function test_scoped_seeding_does_not_create_missing_roles_or_activate_disabled_hr_roles(): void
    {
        $inactiveRole = Role::query()->create(['code' => 'hr_admin', 'name' => 'Disabled HR', 'is_active' => false]);
        $employeeRole = Role::query()->create(['code' => 'employee', 'name' => 'Employee', 'is_active' => true]);
        $activeHr = Role::query()->create(['code' => 'hr_staff', 'name' => 'HR Staff', 'is_active' => true]);

        $this->seed(RecruitmentPermissionSeeder::class);

        $this->assertFalse($inactiveRole->refresh()->is_active);
        $this->assertSame(0, $inactiveRole->permissions()->count());
        $this->assertSame(0, $employeeRole->permissions()->count());
        $this->assertSame(3, $activeHr->permissions()->count());
        $this->assertDatabaseCount('roles', 3);
        $this->assertDatabaseCount('permissions', 3);
        $this->assertDatabaseMissing('roles', ['code' => 'super_admin']);
    }

    public static function uniqueApplicantIdentifiers(): array
    {
        return ['email' => ['email'], 'uuid' => ['uuid']];
    }

    public static function historicalParents(): array
    {
        return ['applicant' => ['applicant'], 'application' => ['application'], 'exam' => ['exam']];
    }

    public static function recruitmentRoles(): array
    {
        return [
            'super admin' => ['super_admin', true],
            'hr admin' => ['hr_admin', true],
            'hr staff' => ['hr_staff', true],
            'payroll admin' => ['payroll_admin', false],
            'manager' => ['manager', false],
            'supervisor' => ['supervisor', false],
            'employee' => ['employee', false],
        ];
    }
}
