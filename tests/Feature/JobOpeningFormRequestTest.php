<?php

namespace Tests\Feature;

use App\Http\Requests\StoreJobOpeningRequest;
use App\Http\Requests\UpdateJobOpeningRequest;
use App\Models\JobOpening;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\FormRequestTestCase;

class JobOpeningFormRequestTest extends FormRequestTestCase
{
    public function test_staff_can_validate_a_full_posting_and_forged_audit_fields_are_excluded(): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        $input = self::input();

        $validated = $this->validateRequest(StoreJobOpeningRequest::class, $input + ['created_by' => 999, 'position_id' => 1, 'salary_currency' => 'USD'], $this->user('recruitment.manage'));

        $this->assertSame($input, $validated);
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_posting_fields_are_rejected_before_persistence(string $field, mixed $value): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        $input = array_replace(self::input(), [$field => $value]);

        $this->assertInvalidRequest(StoreJobOpeningRequest::class, $input, $this->user('recruitment.manage'), fields: [$field]);

        $this->assertDatabaseCount('job_openings', 0);
    }

    public function test_an_open_posting_without_status_cannot_use_a_past_deadline_and_returns_the_explanatory_message(): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        $input = ['title' => 'Records Assistant', 'description' => 'Maintain records.', 'closes_on' => '2026-10-06', 'monthly_salary' => '25000.00'];

        try {
            $this->validateRequest(StoreJobOpeningRequest::class, $input, $this->user('recruitment.manage'));
            $this->fail('An open position must not be posted with an expired deadline.');
        } catch (ValidationException $exception) {
            $this->assertSame(['An open position must close today or later.'], $exception->errors()['closes_on']);
        }
    }

    public function test_closed_postings_can_keep_past_deadlines_and_today_is_valid_for_open_postings(): void
    {
        $this->travelTo('2026-10-07 23:59:00');
        $staff = $this->user('recruitment.manage');
        $closed = array_replace(self::input(), ['status' => 'closed', 'closes_on' => '2020-01-01']);
        $today = array_replace(self::input(), ['closes_on' => '2026-10-07']);

        $this->assertSame($closed, $this->validateRequest(StoreJobOpeningRequest::class, $closed, $staff));
        $this->assertSame($today, $this->validateRequest(StoreJobOpeningRequest::class, $today, $staff));
    }

    public function test_staff_without_manage_permission_cannot_validate_posting_creation_or_update(): void
    {
        $staff = $this->user('recruitment.view');
        $opening = JobOpening::factory()->create();

        $this->assertUnauthorizedRequest(StoreJobOpeningRequest::class, self::input(), $staff);
        $this->assertUnauthorizedRequest(UpdateJobOpeningRequest::class, self::input(), $staff, ['jobOpening' => $opening]);
    }

    public function test_updating_requires_the_exact_route_binding_and_explicit_status(): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        $staff = $this->user('recruitment.manage');
        $opening = JobOpening::factory()->closed()->create();
        $input = self::input();

        $this->assertUnauthorizedRequest(UpdateJobOpeningRequest::class, $input, $staff, ['opening' => $opening]);
        $this->assertSame($input, $this->validateRequest(UpdateJobOpeningRequest::class, $input, $staff, ['jobOpening' => $opening]));
        unset($input['status']);
        $this->assertInvalidRequest(UpdateJobOpeningRequest::class, $input, $staff, ['jobOpening' => $opening], ['status']);

        $this->assertSame('closed', $opening->refresh()->status);
    }

    public static function invalidFields(): array
    {
        return [
            'missing title' => ['title', null],
            'oversized title' => ['title', str_repeat('a', 181)],
            'nonstring title' => ['title', ['name']],
            'missing description' => ['description', null],
            'oversized description' => ['description', str_repeat('a', 10001)],
            'nonstring description' => ['description', []],
            'oversized requirements' => ['requirements', str_repeat('a', 10001)],
            'nonstring requirements' => ['requirements', []],
            'oversized location' => ['location', str_repeat('a', 181)],
            'nonstring location' => ['location', []],
            'oversized employment type' => ['employment_type', str_repeat('a', 51)],
            'nonstring employment type' => ['employment_type', []],
            'no vacancies' => ['vacancies', 0],
            'too many vacancies' => ['vacancies', 1001],
            'fractional vacancies' => ['vacancies', 1.5],
            'null vacancies' => ['vacancies', null],
            'unknown status' => ['status', 'draft'],
            'null status' => ['status', null],
            'invalid date' => ['closes_on', '2026-02-30'],
            'noncalendar format' => ['closes_on', 'October 20, 2026'],
            'timestamp instead of date' => ['closes_on', '2026-10-20T00:00:00Z'],
            'expired open posting' => ['closes_on', '2026-10-06'],
            'missing salary' => ['monthly_salary', null],
            'empty salary' => ['monthly_salary', ''],
            'zero salary' => ['monthly_salary', '0.00'],
            'negative salary' => ['monthly_salary', '-1.00'],
            'below minimum salary' => ['monthly_salary', '0.001'],
            'salary too many decimals' => ['monthly_salary', '20000.501'],
            'salary too large' => ['monthly_salary', '10000000000000.00'],
            'scientific salary' => ['monthly_salary', '2e4'],
            'comma salary' => ['monthly_salary', '25,000.00'],
            'currency symbol salary' => ['monthly_salary', '₱25000.00'],
            'plus signed salary' => ['monthly_salary', '+25000.00'],
            'missing integer digits salary' => ['monthly_salary', '.50'],
            'array salary' => ['monthly_salary', ['25000.00']],
            'boolean salary' => ['monthly_salary', true],
        ];
    }

    #[TestWith(['0.01'])]
    #[TestWith(['20000.50'])]
    #[TestWith(['9999999999999.99'])]
    public function test_valid_monthly_salary_boundaries_remain_exact_strings_for_creation_and_update(string $salary): void
    {
        $this->travelTo('2026-10-07 12:00:00');
        $staff = $this->user('recruitment.manage');
        $opening = JobOpening::factory()->create();
        $input = array_replace(self::input(), ['monthly_salary' => $salary]);

        $this->assertSame($salary, $this->validateRequest(StoreJobOpeningRequest::class, $input, $staff)['monthly_salary']);
        $this->assertSame($salary, $this->validateRequest(UpdateJobOpeningRequest::class, $input, $staff, ['jobOpening' => $opening])['monthly_salary']);
    }

    public function test_both_creating_and_updating_closed_postings_require_an_explicit_salary(): void
    {
        $staff = $this->user('recruitment.manage');
        $opening = JobOpening::factory()->closed()->create();
        $input = ['title' => 'Office Clerk', 'description' => 'Support office operations.', 'status' => 'closed'];

        $this->assertInvalidRequest(StoreJobOpeningRequest::class, $input, $staff, fields: ['monthly_salary']);
        $this->assertInvalidRequest(UpdateJobOpeningRequest::class, $input, $staff, ['jobOpening' => $opening], ['monthly_salary']);
    }

    private static function input(): array
    {
        return [
            'title' => 'Records Assistant', 'description' => 'Maintain records.',
            'requirements' => 'Office experience.', 'location' => 'Manila',
            'employment_type' => 'Regular', 'vacancies' => 3, 'status' => 'open', 'closes_on' => '2026-10-20',
            'monthly_salary' => '25000.00',
        ];
    }
}
