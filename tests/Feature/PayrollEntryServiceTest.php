<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollEntryService;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\FormRequestTestCase;

class PayrollEntryServiceTest extends FormRequestTestCase
{
    public function test_repeated_preparation_updates_the_compensation_snapshot_without_duplicating_or_clearing_calculated_items(): void
    {
        $this->travelTo('2026-10-05 09:00:00');
        $employee = $this->employee();
        $run = $this->payrollRun();
        $this->compensation($employee, '2026-01-01', '12345.67', 'USD', 'monthly');
        $service = $this->app->make(PayrollEntryService::class);
        $entry = $service->prepare($run, $employee, '2026-10-05');
        $calculated = $service->replaceItems($entry, [
            $this->item('SALARY', 'earning', '10000.01'),
            $this->item('TAX', 'deduction', '999.99'),
        ]);
        $itemIds = $calculated->items->pluck('id')->all();
        $this->compensation($employee, '2026-10-01', '54321.09', 'USD', 'semimonthly');
        $this->travel(1)->minutes();

        $prepared = $service->prepare($run, $employee, '2026-10-05');

        $this->assertSame($entry->id, $prepared->id);
        $this->assertSame('54321.09', $prepared->basic_salary);
        $this->assertSame('USD', $prepared->currency);
        $this->assertSame('semimonthly', $prepared->pay_frequency);
        $this->assertSame('10000.01', $prepared->gross_pay);
        $this->assertSame('999.99', $prepared->total_deductions);
        $this->assertSame('9000.02', $prepared->net_pay);
        $this->assertSame($itemIds, $prepared->items->pluck('id')->all());
        $this->assertSame('2026-10-05 09:00:00', $prepared->calculated_at->toDateTimeString());
        $this->assertDatabaseCount('payroll_entries', 1);
        $this->assertDatabaseCount('payroll_entry_items', 2);
    }

    #[DataProvider('precisionCases')]
    public function test_payroll_totals_preserve_centavos_for_large_values_and_allow_deductions_to_exceed_earnings(
        array $items, string $gross, string $deductions, string $net,
    ): void {
        $this->travelTo('2026-10-05 09:00:00');
        $entry = $this->entry();

        $calculated = $this->app->make(PayrollEntryService::class)->replaceItems($entry, $items);

        $this->assertSame($gross, $calculated->gross_pay);
        $this->assertSame($deductions, $calculated->total_deductions);
        $this->assertSame($net, $calculated->net_pay);
        $this->assertSame('USD', $calculated->currency);
        $this->assertSame('2026-10-05 09:00:00', $calculated->calculated_at->toDateTimeString());
        $this->assertSame(count($items), $calculated->items->count());
        $this->assertSame($net, $entry->fresh()->net_pay);
    }

    public static function precisionCases(): array
    {
        return [
            'large exact earning total' => [[
                ['code' => 'LARGE', 'name' => 'Large earning', 'type' => 'earning', 'amount' => '999999999999.97'],
                ['code' => 'CENT', 'name' => 'Two centavos', 'type' => 'earning', 'amount' => '0.02'],
                ['code' => 'TAX', 'name' => 'Tax', 'type' => 'deduction', 'amount' => '0.01'],
            ], '999999999999.99', '0.01', '999999999999.98'],
            'negative net retains exact sign and centavo' => [[
                ['code' => 'PAY', 'name' => 'Small earning', 'type' => 'earning', 'amount' => '0.10'],
                ['code' => 'TAX', 'name' => 'Larger deduction', 'type' => 'deduction', 'amount' => '0.11'],
            ], '0.10', '0.11', '-0.01'],
            'quantity and rate are trace values rather than recalculating the supplied amount' => [[
                ['code' => 'PAY', 'name' => 'Recorded earning', 'type' => 'earning', 'amount' => '12.34', 'quantity' => '2.5000', 'rate' => '99.9999'],
            ], '12.34', '0.00', '12.34'],
        ];
    }

    #[DataProvider('invalidReplacementItems')]
    public function test_replacement_failure_restores_deleted_items_and_previous_totals_atomically(array $badItem, string $message): void
    {
        $this->travelTo('2026-10-05 09:00:00');
        $entry = $this->entry();
        $service = $this->app->make(PayrollEntryService::class);
        $original = $service->replaceItems($entry, [
            $this->item('OLD-PAY', 'earning', '1000.01'),
            $this->item('OLD-TAX', 'deduction', '100.02'),
        ]);
        $before = $original->getAttributes();
        $itemsBefore = $original->items()->orderBy('id')->get()->map(fn ($item): array => $item->getAttributes())->all();
        $this->travel(1)->minutes();

        try {
            $service->replaceItems($entry, [$this->item('NEW', 'earning', '777.77'), $badItem]);
            $this->fail('Expected an invalid replacement to restore the complete old payroll.');
        } catch (RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }

        $this->assertSame($before, $original->fresh()->getAttributes());
        $this->assertSame($itemsBefore, $original->items()->orderBy('id')->get()->map(fn ($item): array => $item->getAttributes())->all());
        $this->assertDatabaseCount('payroll_entry_items', 2);
        $this->assertDatabaseMissing('payroll_entry_items', ['code' => 'NEW']);
        $this->assertSame('draft', $original->payrollRun->fresh()->status);
    }

    public static function invalidReplacementItems(): array
    {
        return [
            'negative earning' => [[
                'code' => 'NEGATIVE', 'name' => 'Invalid earning', 'type' => 'earning', 'amount' => '-0.01',
            ], 'Payroll item NEGATIVE must not have a negative amount.'],
            'negative deduction' => [[
                'code' => 'NEGATIVE', 'name' => 'Invalid deduction', 'type' => 'deduction', 'amount' => '-10.00',
            ], 'Payroll item NEGATIVE must not have a negative amount.'],
            'unsupported item type' => [[
                'code' => 'OTHER', 'name' => 'Unsupported item', 'type' => 'credit', 'amount' => '10.00',
            ], 'Unsupported payroll item type: credit.'],
        ];
    }

    public function test_preparation_uses_persisted_editability_and_rolls_back_without_changing_existing_snapshot(): void
    {
        $employee = $this->employee();
        $run = $this->payrollRun();
        $this->compensation($employee, '2026-01-01', '12345.67', 'PHP', 'monthly');
        $service = $this->app->make(PayrollEntryService::class);
        $entry = $service->prepare($run, $employee, '2026-10-05');
        $before = $entry->getAttributes();
        $this->compensation($employee, '2026-10-01', '99999.99', 'USD', 'monthly');
        $run->update(['status' => 'completed']);
        $run->status = 'draft';

        try {
            $service->prepare($run, $employee, '2026-10-05');
            $this->fail('Expected a stale editable caller to respect the persisted completed run.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Payroll entries cannot be modified when payroll run status is completed.', $exception->getMessage());
        }

        $this->assertSame($before, $entry->fresh()->getAttributes());
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseCount('payroll_entries', 1);
    }

    private function payrollRun(): PayrollRun
    {
        $period = PayrollPeriod::query()->create([
            'code' => 'CALC-'.Str::uuid(), 'period_start' => '2026-10-01',
            'period_end' => '2026-10-31', 'pay_date' => '2026-11-05',
            'pay_frequency' => 'monthly', 'status' => 'open',
        ]);

        return PayrollRun::query()->create([
            'payroll_period_id' => $period->id, 'run_number' => 1, 'status' => 'draft',
        ]);
    }

    private function entry(): PayrollEntry
    {
        return PayrollEntry::query()->create([
            'payroll_run_id' => $this->payrollRun()->id, 'employee_id' => $this->employee()->id,
            'basic_salary' => '1000.00', 'pay_frequency' => 'monthly', 'currency' => 'USD',
            'gross_pay' => '0.00', 'total_deductions' => '0.00', 'net_pay' => '0.00', 'status' => 'draft',
        ]);
    }

    /** @return array{code: string, name: string, type: string, amount: string} */
    private function item(string $code, string $type, string $amount): array
    {
        return ['code' => $code, 'name' => $code, 'type' => $type, 'amount' => $amount];
    }

    private function compensation(Employee $employee, string $start, string $salary, string $currency, string $frequency): void
    {
        EmployeeCompensation::query()->create([
            'employee_id' => $employee->id, 'basic_salary' => $salary,
            'pay_frequency' => $frequency, 'currency' => $currency,
            'effective_from' => $start, 'effective_to' => null,
        ]);
    }
}
