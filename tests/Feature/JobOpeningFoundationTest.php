<?php

namespace Tests\Feature;

use App\Models\JobApplication;
use App\Models\JobOpening;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\FormRequestTestCase;

class JobOpeningFoundationTest extends FormRequestTestCase
{
    public function test_additive_schema_keeps_legacy_applications_and_applies_opening_defaults(): void
    {
        $legacy = JobApplication::factory()->create(['desired_position' => 'Legacy position']);
        $openingId = DB::table('job_openings')->insertGetId([
            'title' => 'Records Assistant', 'description' => 'Maintain records.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTrue(Schema::hasColumn('job_applications', 'job_opening_id'));
        $this->assertDatabaseHas('job_openings', ['id' => $openingId, 'vacancies' => 1, 'status' => 'open']);
        $this->assertDatabaseHas('job_applications', ['id' => $legacy->id, 'desired_position' => 'Legacy position', 'job_opening_id' => null]);
    }

    public function test_salary_migration_preserves_existing_openings_and_applications_without_fabricating_a_salary(): void
    {
        $legacy = JobApplication::factory()->create(['desired_position' => 'Legacy position']);
        $migration = require database_path('migrations/2026_10_07_200443_add_monthly_salary_to_recruitment_tables.php');
        $migration->down();
        $openingId = DB::table('job_openings')->insertGetId([
            'title' => 'Legacy opening', 'description' => 'Existing duties.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertTrue(Schema::hasColumn('job_openings', 'monthly_salary'));
        $this->assertTrue(Schema::hasColumn('job_applications', 'monthly_salary'));
        $this->assertDatabaseHas('job_openings', ['id' => $openingId, 'title' => 'Legacy opening', 'monthly_salary' => null]);
        $this->assertDatabaseHas('job_applications', ['id' => $legacy->id, 'desired_position' => 'Legacy position', 'monthly_salary' => null]);
    }

    public function test_relationships_and_casts_preserve_the_posting_application_and_creator(): void
    {
        $staff = $this->user();
        $opening = JobOpening::factory()->for($staff, 'creator')->create(['vacancies' => '3', 'closes_on' => '2026-10-20']);
        $application = JobApplication::factory()->for($opening, 'jobOpening')->create();

        $this->assertTrue($opening->creator->is($staff));
        $this->assertTrue($opening->applications()->sole()->is($application));
        $this->assertTrue($application->jobOpening->is($opening));
        $this->assertSame(3, $opening->vacancies);
        $this->assertSame('2026-10-20', $opening->closes_on->toDateString());
    }

    public function test_salary_casts_preserve_decimal_strings_and_null_legacy_salaries(): void
    {
        $opening = JobOpening::factory()->create(['monthly_salary' => '20000.50']);
        $application = JobApplication::factory()->for($opening, 'jobOpening')->create(['monthly_salary' => '20000.50']);
        $legacy = JobApplication::factory()->create();

        $this->assertSame('20000.50', $opening->refresh()->monthly_salary);
        $this->assertSame('20000.50', $application->refresh()->monthly_salary);
        $this->assertNull($legacy->refresh()->monthly_salary);
        $this->assertSame('9999999999999.99', JobOpening::factory()->make(['monthly_salary' => '9999999999999.99'])->monthly_salary);
        $this->assertSame('9999999999999.99', JobApplication::factory()->make(['monthly_salary' => '9999999999999.99'])->monthly_salary);
    }

    public function test_unpriced_and_zero_salary_openings_are_not_accepting_applications(): void
    {
        $this->freezeTime();
        $available = JobOpening::factory()->create(['monthly_salary' => '0.01']);
        $legacy = JobOpening::factory()->create(['monthly_salary' => null]);
        $zero = JobOpening::factory()->create(['monthly_salary' => '0.00']);

        $this->assertSame([$available->id], JobOpening::query()->accepting()->pluck('id')->all());
        $this->assertTrue($available->acceptingApplications());
        $this->assertFalse($legacy->acceptingApplications());
        $this->assertFalse($zero->acceptingApplications());
    }

    public function test_openings_with_application_history_cannot_be_deleted(): void
    {
        $opening = JobOpening::factory()->create();
        $application = JobApplication::factory()->for($opening, 'jobOpening')->create();

        try {
            $opening->delete();
        } catch (QueryException) {
            $this->assertModelExists($opening);
            $this->assertModelExists($application);

            return;
        }

        $this->fail('An opening with application history must prevent deletion.');
    }

    public function test_deleting_the_creator_keeps_the_posting_with_a_nullable_staff_reference(): void
    {
        $staff = $this->user();
        $opening = JobOpening::factory()->for($staff, 'creator')->create();

        $staff->delete();

        $this->assertModelExists($opening);
        $this->assertNull($opening->refresh()->created_by);
    }

    public function test_accepting_scope_and_status_include_the_closing_day_but_exclude_expired_closed_postings(): void
    {
        $this->travelTo('2026-10-07 23:59:00');
        $open = JobOpening::factory()->create();
        $closingToday = JobOpening::factory()->create(['closes_on' => '2026-10-07']);
        $future = JobOpening::factory()->create(['closes_on' => '2026-10-08']);
        $expired = JobOpening::factory()->expired()->create();
        $closed = JobOpening::factory()->closed()->create();

        $this->assertSame([$open->id, $closingToday->id, $future->id], JobOpening::query()->accepting()->orderBy('id')->pluck('id')->all());
        $this->assertTrue($open->acceptingApplications());
        $this->assertTrue($closingToday->acceptingApplications());
        $this->assertTrue($future->acceptingApplications());
        $this->assertFalse($expired->acceptingApplications());
        $this->assertFalse($closed->acceptingApplications());
    }
}
