<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Services\Payroll\EmployeeCompensationResolver;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\FormRequestTestCase;

class EmployeeCompensationResolverTest extends FormRequestTestCase
{
    #[DataProvider('effectiveBoundaries')]
    public function test_compensation_effectivity_includes_both_dates_and_accepts_date_objects(string $date): void
    {
        $employee = $this->employee();
        $compensation = $this->compensation($employee, '2026-10-01', '2026-10-31', '12345.67');
        $this->compensation($this->employee(), '2026-01-01', null, '99999.99');

        $resolved = $this->app->make(EmployeeCompensationResolver::class)->resolve($employee, CarbonImmutable::parse($date));

        $this->assertSame($compensation->id, $resolved->id);
        $this->assertSame($employee->id, $resolved->employee_id);
        $this->assertSame('12345.67', $resolved->basic_salary);
        $this->assertSame('USD', $resolved->currency);
        $this->assertSame('monthly', $resolved->pay_frequency);
    }

    public static function effectiveBoundaries(): array
    {
        return ['first effective day' => ['2026-10-01'], 'last effective day' => ['2026-10-31']];
    }

    public function test_overlapping_effective_compensations_choose_latest_start_then_latest_record(): void
    {
        $employee = $this->employee();
        $this->compensation($employee, '2026-01-01', null, '10000.00');
        $this->compensation($employee, '2026-10-01', null, '20000.00');
        $selected = $this->compensation($employee, '2026-10-01', null, '30000.01');
        $this->compensation($employee, '2026-11-01', null, '40000.00');
        $this->compensation($employee, '2026-10-02', '2026-10-03', '50000.00');

        $resolved = $this->app->make(EmployeeCompensationResolver::class)->resolve($employee, '2026-10-05');

        $this->assertSame($selected->id, $resolved->id);
        $this->assertSame('30000.01', $resolved->basic_salary);
    }

    #[DataProvider('uncoveredDates')]
    public function test_a_date_without_employee_compensation_fails_without_changing_any_records(string $date): void
    {
        $employee = $this->employee();
        $compensation = $this->compensation($employee, '2026-10-01', '2026-10-31', '12345.67');
        $this->compensation($this->employee(), '2026-01-01', null, '99999.99');
        $before = $compensation->fresh()->getAttributes();

        try {
            $this->app->make(EmployeeCompensationResolver::class)->resolve($employee, $date);
            $this->fail('Expected no compensation outside the employee effective range.');
        } catch (RuntimeException $exception) {
            $this->assertSame('No effective compensation exists for this employee on '.$date.'.', $exception->getMessage());
        }

        $this->assertSame($before, $compensation->fresh()->getAttributes());
        $this->assertDatabaseCount('employee_compensations', 2);
        $this->assertDatabaseCount('payroll_entries', 0);
    }

    public static function uncoveredDates(): array
    {
        return ['before effectivity' => ['2026-09-30'], 'after effectivity' => ['2026-11-01']];
    }

    private function compensation(Employee $employee, string $start, ?string $end, string $salary): EmployeeCompensation
    {
        return EmployeeCompensation::query()->create([
            'employee_id' => $employee->id, 'basic_salary' => $salary,
            'pay_frequency' => 'monthly', 'currency' => 'USD',
            'effective_from' => $start, 'effective_to' => $end,
        ]);
    }
}
