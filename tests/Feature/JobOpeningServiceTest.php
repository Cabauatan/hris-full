<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\JobApplication;
use App\Models\JobOpening;
use App\Services\Recruitment\JobOpeningService;
use App\Services\Recruitment\RecruitmentService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\FormRequestTestCase;

class JobOpeningServiceTest extends FormRequestTestCase
{
    public function test_staff_creation_applies_defaults_and_ignores_forged_creator_and_internal_position(): void
    {
        $staff = $this->user();

        $opening = app(JobOpeningService::class)->create($staff, [
            'title' => '  Records Assistant  ', 'description' => 'Maintain records.',
            'monthly_salary' => '20000.50',
            'created_by' => 999, 'position_id' => 999,
        ]);

        $this->assertDatabaseHas('job_openings', [
            'id' => $opening->id, 'title' => 'Records Assistant', 'description' => 'Maintain records.',
            'created_by' => $staff->id, 'vacancies' => 1, 'status' => 'open', 'closes_on' => null,
        ]);
        $this->assertDatabaseCount('positions', 0);
        $this->assertSame('20000.50', $opening->refresh()->monthly_salary);
    }

    public function test_update_reloads_the_posting_and_keeps_original_creator_and_application_title_snapshot(): void
    {
        $staff = $this->user();
        $opening = JobOpening::factory()->for($staff, 'creator')->create(['title' => 'Original title']);
        $application = app(RecruitmentService::class)->apply(Applicant::factory()->create(), ['job_opening_id' => $opening->id]);

        $updated = app(JobOpeningService::class)->update($opening, [
            'title' => 'Updated title', 'description' => 'New duties.', 'requirements' => 'Two years experience.',
            'location' => 'Manila', 'employment_type' => 'Regular', 'vacancies' => 4,
            'status' => 'closed', 'closes_on' => '2020-01-01', 'created_by' => null,
            'monthly_salary' => '30000.75',
        ]);

        $this->assertSame('Updated title', $updated->title);
        $this->assertDatabaseHas('job_openings', [
            'id' => $opening->id, 'title' => 'Updated title', 'created_by' => $staff->id,
            'status' => 'closed', 'vacancies' => 4,
        ]);
        $this->assertSame('2020-01-01', $updated->refresh()->closes_on->toDateString());
        $this->assertSame('Original title', $application->refresh()->desired_position);
        $this->assertSame('25000.00', $application->monthly_salary);
        $this->assertSame('30000.75', $updated->monthly_salary);
    }

    #[TestWith(['open', '2026-10-06', 'closes_on'])]
    #[TestWith(['hidden', null, 'status'])]
    #[TestWith(['open', '2026-02-30', 'closes_on'])]
    public function test_invalid_opening_state_is_rejected_without_partial_changes(string $status, ?string $closesOn, string $field): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        $opening = JobOpening::factory()->create(['title' => 'Original title']);

        try {
            app(JobOpeningService::class)->update($opening, [
                'title' => 'Changed title', 'description' => 'Changed duties.', 'status' => $status, 'closes_on' => $closesOn,
                'monthly_salary' => '25000.00',
            ]);
            $this->fail('An invalid posting state must not be persisted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }

        $this->assertDatabaseHas('job_openings', ['id' => $opening->id, 'title' => 'Original title', 'status' => 'open', 'closes_on' => null]);
    }

    public function test_application_uses_fresh_posting_title_and_ignores_client_identity_status_and_position(): void
    {
        $applicant = Applicant::factory()->create();
        $other = Applicant::factory()->create();
        $opening = JobOpening::factory()->create(['title' => 'Original title']);
        JobOpening::query()->whereKey($opening)->update(['title' => 'Fresh title', 'monthly_salary' => '20000.50']);

        $application = app(RecruitmentService::class)->apply($applicant, [
            'job_opening_id' => $opening->id, 'desired_position' => 'Forged title',
            'applicant_id' => $other->id, 'status' => 'hired', 'start_date' => '2030-01-01',
            'monthly_salary' => '1.00', 'salary_currency' => 'USD',
        ]);

        $this->assertDatabaseHas('job_applications', [
            'id' => $application->id, 'job_opening_id' => $opening->id, 'applicant_id' => $applicant->id,
            'desired_position' => 'Fresh title', 'status' => 'pending', 'start_date' => null,
        ]);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('employees', 0);
        $this->assertSame('20000.50', $application->refresh()->monthly_salary);
    }

    #[TestWith(['closed'])]
    #[TestWith(['expired'])]
    #[TestWith(['missing'])]
    #[TestWith(['unpriced'])]
    #[TestWith(['zero'])]
    public function test_fresh_closed_expired_or_missing_posting_cannot_receive_an_application(string $state): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        $applicant = Applicant::factory()->create();
        $opening = JobOpening::factory()->create();
        match ($state) {
            'closed' => JobOpening::query()->whereKey($opening)->update(['status' => 'closed']),
            'expired' => JobOpening::query()->whereKey($opening)->update(['closes_on' => '2026-10-06']),
            'missing' => $opening->delete(),
            'unpriced' => JobOpening::query()->whereKey($opening)->update(['monthly_salary' => null]),
            'zero' => JobOpening::query()->whereKey($opening)->update(['monthly_salary' => '0.00']),
        };

        try {
            app(RecruitmentService::class)->apply($applicant, ['job_opening_id' => $opening->id]);
            $this->fail('An unavailable position must not accept an application.');
        } catch (ValidationException $exception) {
            $this->assertSame(['This position is no longer accepting applications.'], $exception->errors()['job_opening_id']);
        }

        $this->assertDatabaseCount('job_applications', 0);
    }

    #[TestWith(['pending'])]
    #[TestWith(['approved'])]
    #[TestWith(['hired'])]
    public function test_one_active_application_is_enforced_across_different_postings_and_legacy_applications(string $status): void
    {
        $applicant = Applicant::factory()->create();
        JobApplication::factory()->for($applicant)->create(['status' => $status]);
        $opening = JobOpening::factory()->create();

        try {
            app(RecruitmentService::class)->apply($applicant, ['job_opening_id' => $opening->id]);
            $this->fail('A legacy active application must still prevent a second application.');
        } catch (RuntimeException $exception) {
            $this->assertSame('You already have an active application.', $exception->getMessage());
        }

        $this->assertDatabaseCount('job_applications', 1);
    }

    public function test_a_rejected_applicant_can_select_another_open_posting_on_its_closing_day(): void
    {
        $this->travelTo('2026-10-07 23:59:00');
        $applicant = Applicant::factory()->create();
        JobApplication::factory()->for($applicant)->create(['status' => 'rejected']);
        $opening = JobOpening::factory()->create(['closes_on' => '2026-10-07']);

        $application = app(RecruitmentService::class)->apply($applicant, ['job_opening_id' => $opening->id]);

        $this->assertSame($opening->id, $application->job_opening_id);
        $this->assertSame('pending', $application->status);
        $this->assertDatabaseCount('job_applications', 2);
    }

    #[TestWith([null])]
    #[TestWith(['0.00'])]
    #[TestWith(['-1.00'])]
    #[TestWith(['20000.501'])]
    #[TestWith(['10000000000000.00'])]
    #[TestWith(['2e4'])]
    public function test_invalid_salary_is_rejected_even_for_closed_posting_service_updates_without_partial_changes(mixed $salary): void
    {
        $opening = JobOpening::factory()->closed()->create(['title' => 'Original title']);

        try {
            app(JobOpeningService::class)->update($opening, [
                'title' => 'Changed title', 'description' => 'Changed duties.', 'status' => 'closed', 'monthly_salary' => $salary,
            ]);
            $this->fail('Invalid salary must not be persisted by the service.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('monthly_salary', $exception->errors());
        }

        $this->assertDatabaseHas('job_openings', ['id' => $opening->id, 'title' => 'Original title', 'status' => 'closed']);
        $this->assertSame('25000.00', $opening->refresh()->monthly_salary);
    }

    public function test_service_creation_requires_salary_and_does_not_fabricate_a_default(): void
    {
        try {
            app(JobOpeningService::class)->create($this->user(), ['title' => 'Office Clerk', 'description' => 'Support office operations.']);
            $this->fail('HR must provide a fixed monthly salary.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('monthly_salary', $exception->errors());
        }

        $this->assertDatabaseCount('job_openings', 0);
    }
}
