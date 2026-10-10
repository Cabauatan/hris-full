<?php

namespace Tests\Feature;

use App\Http\Resources\JobApplicationResource;
use App\Http\Resources\JobOpeningResource;
use App\Models\Applicant;
use App\Models\JobApplication;
use App\Models\JobOpening;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\FormRequestTestCase;

class JobOpeningApiTest extends FormRequestTestCase
{
    public function test_public_catalog_lists_only_available_positions_in_stable_order_including_the_closing_day(): void
    {
        $this->travelTo('2026-10-07 23:59:00');
        $open = JobOpening::factory()->create(['title' => 'Office Clerk']);
        $today = JobOpening::factory()->create(['title' => 'Records Assistant', 'closes_on' => '2026-10-07']);
        JobOpening::factory()->closed()->create();
        JobOpening::factory()->expired()->create();

        $response = $this->getJson('/api/v1/careers/job-openings?status=closed&per_page=1')->assertOk();

        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $today->id)
            ->assertJsonPath('data.0.closes_on', '2026-10-07')->assertJsonPath('data.0.accepting_applications', true)
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.per_page', 1);
        $this->getJson('/api/v1/careers/job-openings?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.id', $open->id);
    }

    public function test_public_search_matches_title_and_location_without_broadening_the_availability_filter(): void
    {
        $manila = JobOpening::factory()->create(['title' => 'Accountant', 'location' => 'Manila']);
        JobOpening::factory()->create(['title' => 'Records Assistant', 'location' => 'Cebu']);
        JobOpening::factory()->closed()->create(['title' => 'Manila Office']);

        $this->getJson('/api/v1/careers/job-openings?search=Manila')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $manila->id);
        $this->getJson('/api/v1/careers/job-openings?search='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_public_details_are_whitelisted_even_when_staff_is_authenticated(): void
    {
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $opening = JobOpening::factory()->for($staff, 'creator')->create([
            'description' => '<script>alert("xss")</script>', 'requirements' => 'Experience required.',
            'location' => 'Manila', 'employment_type' => 'Regular', 'vacancies' => 3,
            'monthly_salary' => '20000.50',
        ]);
        JobApplication::factory()->for($opening, 'jobOpening')->create();

        $response = $this->asStaff($staff)->getJson('/api/v1/careers/job-openings/'.$opening->id)->assertOk();

        $keys = array_keys($response->json('data'));
        sort($keys);
        $this->assertSame([
            'accepting_applications', 'closes_on', 'created_at', 'description', 'employment_type',
            'id', 'location', 'monthly_salary', 'requirements', 'salary_currency', 'status', 'title', 'vacancies',
        ], $keys);
        $response->assertJsonPath('data.description', '<script>alert("xss")</script>')->assertJsonPath('data.vacancies', 3);
        $response->assertJsonPath('data.monthly_salary', '20000.50')->assertJsonPath('data.salary_currency', 'PHP');
        $this->asStaff($staff)->getJson('/api/v1/careers/job-openings')->assertOk()
            ->assertJsonMissingPath('data.0.created_by')->assertJsonMissingPath('data.0.abilities')
            ->assertJsonMissingPath('data.0.applications')->assertJsonMissingPath('data.0.updated_at');
    }

    #[TestWith(['closed'])]
    #[TestWith(['expired'])]
    #[TestWith(['missing'])]
    public function test_unavailable_public_details_return_404(string $state): void
    {
        $opening = JobOpening::factory()->create();
        match ($state) {
            'closed' => $opening->update(['status' => 'closed']),
            'expired' => $opening->update(['closes_on' => now()->subDay()->toDateString()]),
            'missing' => $opening->delete(),
        };

        $this->getJson('/api/v1/careers/job-openings/'.$opening->id)->assertNotFound();
    }

    #[DataProvider('protectedEndpoints')]
    public function test_guest_staff_endpoint_requests_return_401(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized();
    }

    #[DataProvider('protectedEndpoints')]
    public function test_applicant_guard_cannot_access_staff_posting_endpoints_and_receives_401(string $method, string $uri): void
    {
        $applicant = Applicant::factory()->create();

        $this->asApplicant($applicant)->json($method, $uri)->assertUnauthorized();

        $this->assertDatabaseCount('job_openings', 0);
    }

    public function test_staff_without_recruitment_view_receives_403_for_the_staff_catalog(): void
    {
        $this->asStaff($this->user())->getJson('/api/v1/recruitment/job-openings')->assertForbidden();
    }

    public function test_view_only_staff_can_inspect_closed_and_expired_postings_without_update_ability(): void
    {
        $staff = $this->user('recruitment.view');
        $closed = JobOpening::factory()->closed()->for($staff, 'creator')->create();
        $expired = JobOpening::factory()->expired()->create();

        $this->asStaff($staff)->getJson('/api/v1/recruitment/job-openings?status=closed')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $closed->id)
            ->assertJsonPath('data.0.created_by', $staff->id)->assertJsonPath('data.0.accepting_applications', false)
            ->assertJsonPath('data.0.abilities.update', false);
        $this->getJson('/api/v1/recruitment/job-openings/'.$expired->id)->assertOk()
            ->assertJsonPath('data.accepting_applications', false)->assertJsonPath('data.abilities.update', false);
    }

    public function test_view_only_staff_cannot_create_or_update_postings_and_receives_403_without_writes(): void
    {
        $staff = $this->user('recruitment.view');
        $opening = JobOpening::factory()->create(['title' => 'Original title']);

        $this->asStaff($staff)->postJson('/api/v1/recruitment/job-openings', self::input())->assertForbidden();
        $this->putJson('/api/v1/recruitment/job-openings/'.$opening->id, self::input())->assertForbidden();

        $this->assertDatabaseCount('job_openings', 1);
        $this->assertSame('Original title', $opening->refresh()->title);
    }

    public function test_hr_posts_a_position_and_the_public_catalog_immediately_exposes_it_without_staff_fields(): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        $staff = $this->user('recruitment.view', 'recruitment.manage');

        $response = $this->asStaff($staff)->postJson('/api/v1/recruitment/job-openings', self::input() + [
            'created_by' => 999999, 'position_id' => 999999, 'applications_count' => 999,
            'salary_currency' => 'USD',
        ])->assertCreated()->assertJsonPath('data.title', 'Records Assistant')
            ->assertJsonPath('data.created_by', $staff->id)->assertJsonPath('data.abilities.update', true)
            ->assertJsonPath('data.monthly_salary', '20000.50')->assertJsonPath('data.salary_currency', 'PHP');

        $id = $response->json('data.id');
        $this->assertDatabaseHas('job_openings', ['id' => $id, 'title' => 'Records Assistant', 'created_by' => $staff->id, 'status' => 'open', 'vacancies' => 2]);
        $this->assertDatabaseCount('positions', 0);
        $this->getJson('/api/v1/careers/job-openings')->assertOk()->assertJsonPath('data.0.id', $id)
            ->assertJsonMissingPath('data.0.created_by')->assertJsonMissingPath('data.0.abilities')
            ->assertJsonPath('data.0.monthly_salary', '20000.50')->assertJsonPath('data.0.salary_currency', 'PHP');
    }

    public function test_creating_with_required_fields_only_defaults_to_one_open_vacancy_and_no_deadline(): void
    {
        $staff = $this->user('recruitment.manage');

        $response = $this->asStaff($staff)->postJson('/api/v1/recruitment/job-openings', [
            'title' => 'Office Clerk', 'description' => 'Support office operations.',
            'monthly_salary' => '25000.00',
        ])->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonPath('data.vacancies', 1)
            ->assertJsonPath('data.closes_on', null)->assertJsonPath('data.accepting_applications', true);

        $this->assertDatabaseHas('job_openings', ['id' => $response->json('data.id'), 'vacancies' => 1, 'status' => 'open']);
    }

    public function test_put_closes_and_reopens_a_position_while_preserving_application_history_and_creator(): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        $creator = $this->user('recruitment.manage');
        $editor = $this->user('recruitment.view', 'recruitment.manage');
        $opening = JobOpening::factory()->for($creator, 'creator')->create(['title' => 'Original title']);
        $candidate = Applicant::factory()->create();
        $applicationId = $this->asApplicant($candidate)->postJson('/api/v1/applicant/applications', ['job_opening_id' => $opening->id])
            ->assertCreated()->json('data.id');

        $this->asStaff($editor)->putJson('/api/v1/recruitment/job-openings/'.$opening->id, array_replace(self::input(), [
            'status' => 'closed', 'closes_on' => '2026-10-06', 'created_by' => $editor->id,
            'monthly_salary' => '30000.75',
        ]))->assertOk()->assertJsonPath('data.accepting_applications', false)->assertJsonPath('data.created_by', $creator->id);

        $this->getJson('/api/v1/careers/job-openings/'.$opening->id)->assertNotFound();
        $this->assertDatabaseHas('job_applications', ['id' => $applicationId, 'job_opening_id' => $opening->id, 'desired_position' => 'Original title', 'status' => 'pending']);
        $this->assertSame('25000.00', JobApplication::query()->findOrFail($applicationId)->monthly_salary);
        $this->putJson('/api/v1/recruitment/job-openings/'.$opening->id, self::input())->assertOk()
            ->assertJsonPath('data.accepting_applications', true)->assertJsonPath('data.title', 'Records Assistant');
        $this->getJson('/api/v1/careers/job-openings/'.$opening->id)->assertOk()->assertJsonPath('data.title', 'Records Assistant');
    }

    public function test_missing_required_posting_fields_return_422_and_do_not_create_a_position(): void
    {
        $this->asStaff($this->user('recruitment.manage'))->postJson('/api/v1/recruitment/job-openings', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'description', 'monthly_salary']);

        $this->assertDatabaseCount('job_openings', 0);
    }

    public function test_open_expired_deadline_returns_422_and_preserves_posting_when_updating(): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        $opening = JobOpening::factory()->closed()->create(['title' => 'Original title']);

        $this->asStaff($this->user('recruitment.manage'))->putJson('/api/v1/recruitment/job-openings/'.$opening->id, array_replace(self::input(), [
            'closes_on' => '2026-10-06',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['closes_on'])
            ->assertJsonPath('errors.closes_on.0', 'An open position must close today or later.');

        $this->assertDatabaseHas('job_openings', ['id' => $opening->id, 'title' => 'Original title', 'status' => 'closed']);
    }

    public function test_put_without_explicit_status_returns_422_and_does_not_reopen_a_closed_posting(): void
    {
        $opening = JobOpening::factory()->closed()->create();

        $this->asStaff($this->user('recruitment.manage'))->putJson('/api/v1/recruitment/job-openings/'.$opening->id, [
            'title' => 'Updated title', 'description' => 'Updated duties.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $this->assertSame('closed', $opening->refresh()->status);
    }

    #[DataProvider('invalidListFilters')]
    public function test_invalid_public_and_staff_catalog_filters_return_422(string $query, string $field): void
    {
        $this->getJson('/api/v1/careers/job-openings?'.$query)->assertUnprocessable()->assertJsonValidationErrors([$field]);
        $this->asStaff($this->user('recruitment.view'))->getJson('/api/v1/recruitment/job-openings?'.$query)
            ->assertUnprocessable()->assertJsonValidationErrors([$field]);
    }

    public function test_staff_catalog_rejects_unknown_status_filters_with_422(): void
    {
        $this->asStaff($this->user('recruitment.view'))->getJson('/api/v1/recruitment/job-openings?status=draft')
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
    }

    public function test_applicant_selects_the_posted_position_and_client_position_name_is_ignored(): void
    {
        $candidate = Applicant::factory()->create();
        $opening = JobOpening::factory()->create(['title' => 'Finance Assistant']);

        $response = $this->asApplicant($candidate)->postJson('/api/v1/applicant/applications', [
            'job_opening_id' => $opening->id, 'desired_position' => 'Forged title', 'cover_letter' => 'My application.',
            'monthly_salary' => '1.00', 'salary_currency' => 'USD',
        ])->assertCreated()->assertJsonPath('data.job_opening_id', $opening->id)
            ->assertJsonPath('data.desired_position', 'Finance Assistant')
            ->assertJsonPath('data.monthly_salary', '25000.00')->assertJsonPath('data.salary_currency', 'PHP');

        $this->assertDatabaseHas('job_applications', [
            'id' => $response->json('data.id'), 'applicant_id' => $candidate->id, 'job_opening_id' => $opening->id,
            'desired_position' => 'Finance Assistant', 'cover_letter' => 'My application.', 'status' => 'pending',
        ]);
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('users', 0);
    }

    #[TestWith(['closed'])]
    #[TestWith(['expired'])]
    public function test_candidate_selecting_an_unavailable_posting_receives_422_without_an_application(string $state): void
    {
        $candidate = Applicant::factory()->create();
        $opening = $state === 'closed' ? JobOpening::factory()->closed()->create() : JobOpening::factory()->expired()->create();

        $this->asApplicant($candidate)->postJson('/api/v1/applicant/applications', ['job_opening_id' => $opening->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['job_opening_id'])
            ->assertJsonPath('errors.job_opening_id.0', 'This position is no longer accepting applications.');

        $this->assertDatabaseCount('job_applications', 0);
    }

    #[TestWith([999999])]
    #[TestWith([1.5])]
    #[TestWith(['not-an-id'])]
    public function test_invalid_position_selection_returns_422(mixed $id): void
    {
        $this->asApplicant(Applicant::factory()->create())->postJson('/api/v1/applicant/applications', ['job_opening_id' => $id])
            ->assertUnprocessable()->assertJsonValidationErrors(['job_opening_id']);

        $this->assertDatabaseCount('job_applications', 0);
    }

    public function test_legacy_applications_still_appear_with_their_title_and_a_null_opening_link(): void
    {
        $candidate = Applicant::factory()->create();
        $application = JobApplication::factory()->for($candidate)->create(['desired_position' => 'Legacy position']);

        $this->asApplicant($candidate)->getJson('/api/v1/applicant/applications/'.$application->id)->assertOk()
            ->assertJsonPath('data.desired_position', 'Legacy position')->assertJsonPath('data.job_opening_id', null)
            ->assertJsonPath('data.monthly_salary', null)->assertJsonPath('data.salary_currency', 'PHP');
    }

    public function test_public_catalog_has_an_ip_rate_limit_and_returns_429_after_sixty_requests(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.78']);
        RateLimiter::clear(sha1('|198.51.100.78'));

        for ($request = 0; $request < 60; $request++) {
            $this->getJson('/api/v1/careers/job-openings')->assertOk();
        }

        $this->getJson('/api/v1/careers/job-openings')->assertTooManyRequests();
    }

    public function test_legacy_unpriced_and_zero_salary_positions_are_staff_only_until_hr_supplies_a_salary(): void
    {
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $unpriced = JobOpening::factory()->create(['monthly_salary' => null]);
        $zero = JobOpening::factory()->create(['monthly_salary' => '0.00']);

        $this->getJson('/api/v1/careers/job-openings')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/careers/job-openings/'.$unpriced->id)->assertNotFound();
        $this->getJson('/api/v1/careers/job-openings/'.$zero->id)->assertNotFound();
        $this->asStaff($staff)->getJson('/api/v1/recruitment/job-openings/'.$unpriced->id)->assertOk()
            ->assertJsonPath('data.monthly_salary', null)->assertJsonPath('data.salary_currency', 'PHP')
            ->assertJsonPath('data.accepting_applications', false)->assertJsonPath('data.abilities.update', true);
        $this->getJson('/api/v1/recruitment/job-openings')->assertOk()->assertJsonCount(2, 'data');

        $input = ['title' => 'Administrative Assistant', 'description' => 'Support office operations.', 'status' => 'open', 'monthly_salary' => '20000.50'];
        $this->putJson('/api/v1/recruitment/job-openings/'.$unpriced->id, $input)->assertOk()
            ->assertJsonPath('data.monthly_salary', '20000.50')->assertJsonPath('data.accepting_applications', true);
        $this->getJson('/api/v1/careers/job-openings')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $unpriced->id)->assertJsonPath('data.0.monthly_salary', '20000.50');
    }

    #[TestWith([null])]
    #[TestWith(['0.00'])]
    public function test_candidate_cannot_apply_to_a_legacy_unpriced_or_zero_salary_posting_and_receives_422(?string $salary): void
    {
        $opening = JobOpening::factory()->create(['monthly_salary' => $salary]);

        $this->asApplicant(Applicant::factory()->create())->postJson('/api/v1/applicant/applications', ['job_opening_id' => $opening->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['job_opening_id']);

        $this->assertDatabaseCount('job_applications', 0);
    }

    public function test_posting_create_and_update_without_salary_return_422_even_when_closed_and_preserve_prior_salary(): void
    {
        $opening = JobOpening::factory()->closed()->create(['monthly_salary' => '20000.50']);
        $input = ['title' => 'Office Clerk', 'description' => 'Support office operations.', 'status' => 'closed'];

        $this->asStaff($this->user('recruitment.manage'))->postJson('/api/v1/recruitment/job-openings', $input)
            ->assertUnprocessable()->assertJsonValidationErrors(['monthly_salary']);
        $this->putJson('/api/v1/recruitment/job-openings/'.$opening->id, $input)
            ->assertUnprocessable()->assertJsonValidationErrors(['monthly_salary']);

        $this->assertDatabaseCount('job_openings', 1);
        $this->assertSame('20000.50', $opening->refresh()->monthly_salary);
    }

    public function test_salary_with_excess_precision_returns_422_and_does_not_create_a_posting(): void
    {
        $this->asStaff($this->user('recruitment.manage'))->postJson('/api/v1/recruitment/job-openings', array_replace(self::input(), [
            'monthly_salary' => '20000.501',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['monthly_salary']);

        $this->assertDatabaseCount('job_openings', 0);
    }

    public function test_resources_preserve_the_maximum_decimal_salary_as_a_string_without_float_conversion(): void
    {
        $request = Request::create('/api/v1/careers/job-openings');
        $request->setUserResolver(static fn (): null => null);
        $opening = new JobOpening(['monthly_salary' => '9999999999999.99', 'status' => 'open']);
        $application = new JobApplication(['monthly_salary' => '9999999999999.99']);

        $this->assertSame('9999999999999.99', (new JobOpeningResource($opening))->resolve($request)['monthly_salary']);
        $this->assertSame('PHP', (new JobOpeningResource($opening))->resolve($request)['salary_currency']);
        $this->assertSame('9999999999999.99', (new JobApplicationResource($application))->resolve($request)['monthly_salary']);
        $this->assertSame('PHP', (new JobApplicationResource($application))->resolve($request)['salary_currency']);
    }

    public static function protectedEndpoints(): array
    {
        return [
            'index' => ['GET', '/api/v1/recruitment/job-openings'],
            'create' => ['POST', '/api/v1/recruitment/job-openings'],
            'show' => ['GET', '/api/v1/recruitment/job-openings/999999'],
            'update' => ['PUT', '/api/v1/recruitment/job-openings/999999'],
        ];
    }

    public static function invalidListFilters(): array
    {
        return [
            'zero page size' => ['per_page=0', 'per_page'],
            'overlarge page size' => ['per_page=101', 'per_page'],
            'fractional page size' => ['per_page=1.5', 'per_page'],
            'zero page' => ['page=0', 'page'],
            'noninteger page' => ['page=wrong', 'page'],
            'oversized search' => ['search='.str_repeat('a', 181), 'search'],
            'array search' => ['search[]=office', 'search'],
        ];
    }

    private static function input(): array
    {
        return [
            'title' => 'Records Assistant', 'description' => 'Maintain company records.',
            'requirements' => 'Office experience.', 'location' => 'Manila', 'employment_type' => 'Regular',
            'vacancies' => 2, 'status' => 'open', 'closes_on' => '2026-10-20',
            'monthly_salary' => '20000.50',
        ];
    }

    private function asStaff(User $staff): static
    {
        Auth::forgetGuards();

        return $this->actingAs($staff, 'web');
    }

    private function asApplicant(Applicant $applicant): static
    {
        Auth::forgetGuards();

        return $this->actingAs($applicant, 'applicant');
    }
}
