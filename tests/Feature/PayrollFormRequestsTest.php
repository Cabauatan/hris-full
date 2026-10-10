<?php

namespace Tests\Feature;

use App\Http\Requests\CompletePayrollRunRequest;
use App\Http\Requests\FinalizePayrollRunRequest;
use App\Http\Requests\PreparePayrollEntryRequest;
use App\Http\Requests\ReplacePayrollEntryItemsRequest;
use App\Http\Requests\StartPayrollRunRequest;
use App\Http\Requests\StorePayrollRunRequest;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class PayrollFormRequestsTest extends FormRequestTestCase
{
    #[DataProvider('validRunNumbers')]
    public function test_payroll_run_accepts_schema_safe_run_numbers(int $number): void
    {
        $actor = $this->user('payroll.process');
        $period = $this->period();

        $validated = $this->validateRequest(StorePayrollRunRequest::class, [
            'payroll_period_id' => $period->id,
            'run_number' => $number,
        ], $actor);

        $this->assertSame([
            'payroll_period_id' => $period->id,
            'run_number' => $number,
        ], $validated);
    }

    public static function validRunNumbers(): array
    {
        return [
            'first run' => [1],
            'unsigned integer maximum' => [4294967295],
        ];
    }

    public function test_payroll_run_only_returns_client_owned_creation_fields(): void
    {
        $actor = $this->user('payroll.process');
        $period = $this->period();

        $validated = $this->validateRequest(StorePayrollRunRequest::class, [
            'payroll_period_id' => $period->id,
            'run_number' => 1,
            'remarks' => 'Supplemental payroll.',
            'status' => 'finalized',
            'created_by_user_id' => 999999,
            'started_at' => '2026-01-01 00:00:00',
            'completed_at' => '2026-01-01 00:00:00',
            'finalized_at' => '2026-01-01 00:00:00',
            'finalized_by_user_id' => 999999,
        ], $actor);

        $this->assertSame([
            'payroll_period_id' => $period->id,
            'run_number' => 1,
            'remarks' => 'Supplemental payroll.',
        ], $validated);
    }

    #[DataProvider('invalidRunInput')]
    public function test_payroll_run_rejects_invalid_creation_input(array $changes, array $fields): void
    {
        $actor = $this->user('payroll.process');
        $period = $this->period();

        $this->assertInvalidRequest(StorePayrollRunRequest::class, array_replace([
            'payroll_period_id' => $period->id,
            'run_number' => 1,
        ], $changes), $actor, [], $fields);
    }

    public static function invalidRunInput(): array
    {
        return [
            'missing period' => [['payroll_period_id' => null], ['payroll_period_id']],
            'unknown period' => [['payroll_period_id' => 999999], ['payroll_period_id']],
            'period is an array' => [['payroll_period_id' => [1]], ['payroll_period_id']],
            'missing run number' => [['run_number' => null], ['run_number']],
            'zero run number' => [['run_number' => 0], ['run_number']],
            'negative run number' => [['run_number' => -1], ['run_number']],
            'unsigned integer overflow' => [['run_number' => 4294967296], ['run_number']],
            'fractional run number' => [['run_number' => 1.5], ['run_number']],
            'run number is a boolean' => [['run_number' => true], ['run_number']],
            'run number is an array' => [['run_number' => [1]], ['run_number']],
            'remarks are an array' => [['remarks' => ['Payroll']], ['remarks']],
        ];
    }

    public function test_payroll_run_rejects_duplicate_number_in_the_same_period(): void
    {
        $actor = $this->user('payroll.process');
        $period = $this->period();
        $this->payrollRun(['payroll_period_id' => $period->id, 'run_number' => 2]);

        $this->assertInvalidRequest(StorePayrollRunRequest::class, [
            'payroll_period_id' => $period->id,
            'run_number' => 2,
        ], $actor, [], ['run_number']);
    }

    public function test_payroll_run_accepts_the_same_number_in_a_different_period(): void
    {
        $actor = $this->user('payroll.process');
        $this->payrollRun(['run_number' => 2]);
        $differentPeriod = $this->period();

        $validated = $this->validateRequest(StorePayrollRunRequest::class, [
            'payroll_period_id' => $differentPeriod->id,
            'run_number' => 2,
            'remarks' => null,
        ], $actor);

        $this->assertSame(2, $validated['run_number']);
        $this->assertNull($validated['remarks']);
    }

    public function test_payroll_run_creation_requires_an_active_processing_actor(): void
    {
        $period = $this->period();
        $payload = ['payroll_period_id' => $period->id, 'run_number' => 1];
        $viewer = $this->user('payroll.view');
        $inactiveProcessor = $this->user('payroll.process');
        $inactiveProcessor->update(['is_active' => false]);

        $this->assertUnauthorizedRequest(StorePayrollRunRequest::class, $payload, $viewer);
        $this->assertUnauthorizedRequest(StorePayrollRunRequest::class, $payload, $inactiveProcessor);
        $this->assertUnauthorizedRequest(StorePayrollRunRequest::class, $payload, null);
    }

    #[DataProvider('runActions')]
    public function test_payroll_run_actions_authorize_the_bound_run_and_accept_no_writable_metadata(
        string $class,
        string $permission,
        string $unrelatedPermission,
    ): void {
        $actor = $this->user($permission);
        $run = $this->payrollRun();

        $validated = $this->validateRequest($class, [
            'status' => 'finalized',
            'started_at' => '2026-01-01 00:00:00',
            'completed_at' => '2026-01-01 00:00:00',
            'finalized_at' => '2026-01-01 00:00:00',
            'finalized_by_user_id' => 999999,
        ], $actor, ['payrollRun' => $run]);

        $this->assertSame([], $validated);
    }

    #[DataProvider('runActions')]
    public function test_payroll_run_actions_require_the_specific_permission(
        string $class,
        string $permission,
        string $unrelatedPermission,
    ): void {
        $actor = $this->user($unrelatedPermission);
        $run = $this->payrollRun();

        $this->assertUnauthorizedRequest($class, [], $actor, ['payrollRun' => $run]);
    }

    #[DataProvider('runActions')]
    public function test_payroll_run_actions_reject_inactive_or_missing_actors(
        string $class,
        string $permission,
        string $unrelatedPermission,
    ): void {
        $actor = $this->user($permission);
        $actor->update(['is_active' => false]);
        $run = $this->payrollRun();

        $this->assertUnauthorizedRequest($class, [], $actor, ['payrollRun' => $run]);
        $this->assertUnauthorizedRequest($class, [], null, ['payrollRun' => $run]);
    }

    #[DataProvider('runActions')]
    public function test_payroll_run_actions_require_the_exact_typed_route_binding(
        string $class,
        string $permission,
        string $unrelatedPermission,
    ): void {
        $actor = $this->user($permission);
        $run = $this->payrollRun();

        $this->assertUnauthorizedRequest($class, [], $actor);
        $this->assertUnauthorizedRequest($class, [], $actor, ['payrollRun' => $run->id]);
        $this->assertUnauthorizedRequest($class, [], $actor, ['payrollRun' => new Employee]);
        $this->assertUnauthorizedRequest($class, [], $actor, ['run' => $run]);
    }

    public static function runActions(): array
    {
        return [
            'start' => [StartPayrollRunRequest::class, 'payroll.process', 'payroll.finalize'],
            'complete' => [CompletePayrollRunRequest::class, 'payroll.process', 'payroll.finalize'],
            'finalize' => [FinalizePayrollRunRequest::class, 'payroll.finalize', 'payroll.process'],
        ];
    }

    public function test_payroll_entry_preparation_returns_employee_and_reference_date_only(): void
    {
        $actor = $this->user('payroll.process');
        $run = $this->payrollRun();
        $employee = $this->employee();

        $validated = $this->validateRequest(PreparePayrollEntryRequest::class, [
            'employee_id' => $employee->id,
            'compensation_reference_date' => '2026-10-03',
            'payroll_run_id' => 999999,
            'basic_salary' => '999999.00',
            'pay_frequency' => 'forged',
            'currency' => 'USD',
            'gross_pay' => '999999.00',
            'total_deductions' => '0.00',
            'net_pay' => '999999.00',
            'status' => 'finalized',
            'calculated_at' => '2026-01-01 00:00:00',
        ], $actor, ['payrollRun' => $run]);

        $this->assertSame([
            'employee_id' => $employee->id,
            'compensation_reference_date' => '2026-10-03',
        ], $validated);
    }

    #[DataProvider('invalidPreparationInput')]
    public function test_payroll_entry_preparation_rejects_invalid_employee_or_date(array $changes, array $fields): void
    {
        $actor = $this->user('payroll.process');
        $run = $this->payrollRun();
        $employee = $this->employee();

        $this->assertInvalidRequest(PreparePayrollEntryRequest::class, array_replace([
            'employee_id' => $employee->id,
            'compensation_reference_date' => '2026-10-03',
        ], $changes), $actor, ['payrollRun' => $run], $fields);
    }

    public static function invalidPreparationInput(): array
    {
        return [
            'missing employee' => [['employee_id' => null], ['employee_id']],
            'unknown employee' => [['employee_id' => 999999], ['employee_id']],
            'employee is an array' => [['employee_id' => [1]], ['employee_id']],
            'employee is a boolean' => [['employee_id' => true], ['employee_id']],
            'missing reference date' => [['compensation_reference_date' => null], ['compensation_reference_date']],
            'impossible reference date' => [['compensation_reference_date' => '2026-02-30'], ['compensation_reference_date']],
            'reference date includes time' => [['compensation_reference_date' => '2026-10-03 12:00:00'], ['compensation_reference_date']],
            'reference date is an array' => [['compensation_reference_date' => ['2026-10-03']], ['compensation_reference_date']],
        ];
    }

    public function test_payroll_entry_preparation_requires_active_processing_authority(): void
    {
        $run = $this->payrollRun();
        $employee = $this->employee();
        $payload = ['employee_id' => $employee->id, 'compensation_reference_date' => '2026-10-03'];
        $viewer = $this->user('payroll.view');
        $inactiveProcessor = $this->user('payroll.process');
        $inactiveProcessor->update(['is_active' => false]);

        $this->assertUnauthorizedRequest(PreparePayrollEntryRequest::class, $payload, $viewer, ['payrollRun' => $run]);
        $this->assertUnauthorizedRequest(PreparePayrollEntryRequest::class, $payload, $inactiveProcessor, ['payrollRun' => $run]);
        $this->assertUnauthorizedRequest(PreparePayrollEntryRequest::class, $payload, null, ['payrollRun' => $run]);
    }

    public function test_payroll_entry_preparation_requires_the_exact_typed_run_binding(): void
    {
        $actor = $this->user('payroll.process');
        $run = $this->payrollRun();
        $employee = $this->employee();
        $payload = ['employee_id' => $employee->id, 'compensation_reference_date' => '2026-10-03'];

        $this->assertUnauthorizedRequest(PreparePayrollEntryRequest::class, $payload, $actor);
        $this->assertUnauthorizedRequest(PreparePayrollEntryRequest::class, $payload, $actor, ['payrollRun' => $run->id]);
        $this->assertUnauthorizedRequest(PreparePayrollEntryRequest::class, $payload, $actor, ['payrollRun' => $employee]);
        $this->assertUnauthorizedRequest(PreparePayrollEntryRequest::class, $payload, $actor, ['run' => $run]);
    }

    public function test_payroll_entry_items_accept_signed_quantity_and_rate_without_client_totals(): void
    {
        $actor = $this->user('payroll.process');
        $entry = $this->entry();
        $items = [
            $this->item([
                'quantity' => '-2.2500',
                'rate' => '-100.1250',
                'amount' => '1000.25',
                'remarks' => 'Manual item correction.',
            ]),
            $this->item([
                'code' => 'WITHHOLDING',
                'name' => 'Withholding tax',
                'type' => 'deduction',
                'amount' => '100.25',
                'quantity' => null,
                'rate' => null,
                'remarks' => null,
            ]),
        ];

        $validated = $this->validateRequest(ReplacePayrollEntryItemsRequest::class, [
            'items' => $items,
            'gross_pay' => '999999.00',
            'total_deductions' => '0.00',
            'net_pay' => '999999.00',
            'employee_id' => 999999,
            'status' => 'finalized',
        ], $actor, ['payrollEntry' => $entry]);

        $this->assertSame(['items' => $items], $validated);
    }

    public function test_payroll_entry_items_accept_an_empty_list_to_clear_existing_items(): void
    {
        $actor = $this->user('payroll.process');
        $entry = $this->entry();

        $validated = $this->validateRequest(ReplacePayrollEntryItemsRequest::class, [
            'items' => [],
        ], $actor, ['payrollEntry' => $entry]);

        $this->assertSame(['items' => []], $validated);
    }

    #[DataProvider('validItemNumbers')]
    public function test_payroll_entry_items_accept_schema_safe_decimal_boundaries(array $changes): void
    {
        $actor = $this->user('payroll.process');
        $entry = $this->entry();
        $item = $this->item($changes);

        $validated = $this->validateRequest(ReplacePayrollEntryItemsRequest::class, [
            'items' => [$item],
        ], $actor, ['payrollEntry' => $entry]);

        $this->assertSame(['items' => [$item]], $validated);
    }

    public static function validItemNumbers(): array
    {
        return [
            'zero amount' => [['amount' => '0.00']],
            'maximum amount' => [['amount' => '9999999999999.99']],
            'maximum quantity' => [['quantity' => '99999999.9999']],
            'minimum quantity' => [['quantity' => '-99999999.9999']],
            'maximum rate' => [['rate' => '99999999999.9999']],
            'minimum rate' => [['rate' => '-99999999999.9999']],
        ];
    }

    #[DataProvider('invalidItemsEnvelope')]
    public function test_payroll_entry_items_require_a_present_list_of_item_arrays(array $payload, array $fields): void
    {
        $actor = $this->user('payroll.process');
        $entry = $this->entry();

        $this->assertInvalidRequest(ReplacePayrollEntryItemsRequest::class, $payload, $actor, ['payrollEntry' => $entry], $fields);
    }

    public static function invalidItemsEnvelope(): array
    {
        return [
            'missing items' => [[], ['items']],
            'null items' => [['items' => null], ['items']],
            'items are text' => [['items' => 'BASIC'], ['items']],
            'items are keyed by code' => [['items' => ['BASIC' => ['code' => 'BASIC']]], ['items']],
            'item is text' => [['items' => ['BASIC']], ['items.0']],
        ];
    }

    #[DataProvider('invalidItemInput')]
    public function test_payroll_entry_items_reject_invalid_values_and_untrusted_nested_keys(array $changes, array $fields): void
    {
        $actor = $this->user('payroll.process');
        $entry = $this->entry();

        $this->assertInvalidRequest(ReplacePayrollEntryItemsRequest::class, [
            'items' => [$this->item($changes)],
        ], $actor, ['payrollEntry' => $entry], $fields);
    }

    public static function invalidItemInput(): array
    {
        return [
            'missing code' => [['code' => null], ['items.0.code']],
            'overlong code' => [['code' => str_repeat('A', 101)], ['items.0.code']],
            'code is an array' => [['code' => ['BASIC']], ['items.0.code']],
            'missing name' => [['name' => null], ['items.0.name']],
            'overlong name' => [['name' => str_repeat('A', 151)], ['items.0.name']],
            'unsupported type' => [['type' => 'bonus'], ['items.0.type']],
            'missing amount' => [['amount' => null], ['items.0.amount']],
            'negative amount' => [['amount' => '-0.01'], ['items.0.amount']],
            'amount overflow' => [['amount' => '10000000000000.00'], ['items.0.amount']],
            'amount precision' => [['amount' => '1.001'], ['items.0.amount']],
            'amount exponent' => [['amount' => '1e2'], ['items.0.amount']],
            'amount is a boolean' => [['amount' => true], ['items.0.amount']],
            'amount is an array' => [['amount' => ['1.25']], ['items.0.amount']],
            'quantity overflow' => [['quantity' => '100000000.0000'], ['items.0.quantity']],
            'quantity negative overflow' => [['quantity' => '-100000000.0000'], ['items.0.quantity']],
            'quantity precision' => [['quantity' => '1.00001'], ['items.0.quantity']],
            'quantity is an array' => [['quantity' => ['1.00']], ['items.0.quantity']],
            'rate overflow' => [['rate' => '100000000000.0000'], ['items.0.rate']],
            'rate negative overflow' => [['rate' => '-100000000000.0000'], ['items.0.rate']],
            'rate precision' => [['rate' => '1.00001'], ['items.0.rate']],
            'remarks are an array' => [['remarks' => ['Correction']], ['items.0.remarks']],
            'relationship injection' => [['payroll_entry_id' => 999999], ['items.0']],
            'source type injection' => [['source_type' => 'leave'], ['items.0']],
            'source id injection' => [['source_id' => 999999], ['items.0']],
        ];
    }

    #[DataProvider('itemTypes')]
    public function test_payroll_entry_items_reject_aggregate_overflow_for_each_total(string $type): void
    {
        $actor = $this->user('payroll.process');
        $entry = $this->entry();

        $this->assertInvalidRequest(ReplacePayrollEntryItemsRequest::class, [
            'items' => [
                $this->item(['type' => $type, 'amount' => '9999999999999.99']),
                $this->item(['code' => 'EXTRA', 'type' => $type, 'amount' => '0.01']),
            ],
        ], $actor, ['payrollEntry' => $entry], ['items']);
    }

    public static function itemTypes(): array
    {
        return [
            'earnings total' => ['earning'],
            'deductions total' => ['deduction'],
        ];
    }

    public function test_payroll_entry_items_keep_earning_and_deduction_limits_independent(): void
    {
        $actor = $this->user('payroll.process');
        $entry = $this->entry();
        $items = [
            $this->item(['amount' => '9999999999999.99']),
            $this->item(['code' => 'DEDUCT', 'type' => 'deduction', 'amount' => '9999999999999.99']),
        ];

        $validated = $this->validateRequest(ReplacePayrollEntryItemsRequest::class, ['items' => $items], $actor, ['payrollEntry' => $entry]);

        $this->assertSame(['items' => $items], $validated);
    }

    public function test_payroll_entry_items_require_active_processing_authority(): void
    {
        $entry = $this->entry();
        $viewer = $this->user('payroll.view');
        $inactiveProcessor = $this->user('payroll.process');
        $inactiveProcessor->update(['is_active' => false]);

        $this->assertUnauthorizedRequest(ReplacePayrollEntryItemsRequest::class, ['items' => []], $viewer, ['payrollEntry' => $entry]);
        $this->assertUnauthorizedRequest(ReplacePayrollEntryItemsRequest::class, ['items' => []], $inactiveProcessor, ['payrollEntry' => $entry]);
        $this->assertUnauthorizedRequest(ReplacePayrollEntryItemsRequest::class, ['items' => []], null, ['payrollEntry' => $entry]);
    }

    public function test_payroll_entry_items_require_the_exact_typed_entry_binding(): void
    {
        $actor = $this->user('payroll.process');
        $entry = $this->entry();

        $this->assertUnauthorizedRequest(ReplacePayrollEntryItemsRequest::class, ['items' => []], $actor);
        $this->assertUnauthorizedRequest(ReplacePayrollEntryItemsRequest::class, ['items' => []], $actor, ['payrollEntry' => $entry->id]);
        $this->assertUnauthorizedRequest(ReplacePayrollEntryItemsRequest::class, ['items' => []], $actor, ['payrollEntry' => $entry->payrollRun]);
        $this->assertUnauthorizedRequest(ReplacePayrollEntryItemsRequest::class, ['items' => []], $actor, ['entry' => $entry]);
    }

    private function period(): PayrollPeriod
    {
        return PayrollPeriod::query()->create([
            'code' => 'TEST-'.Str::uuid(),
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'pay_date' => '2026-11-05',
            'pay_frequency' => 'monthly',
            'status' => 'open',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function payrollRun(array $attributes = []): PayrollRun
    {
        return PayrollRun::query()->create(array_replace([
            'payroll_period_id' => $attributes['payroll_period_id'] ?? $this->period()->id,
            'run_number' => 1,
            'status' => 'draft',
        ], $attributes));
    }

    private function entry(): PayrollEntry
    {
        return PayrollEntry::query()->create([
            'payroll_run_id' => $this->payrollRun()->id,
            'employee_id' => $this->employee()->id,
            'basic_salary' => '10000.00',
            'pay_frequency' => 'monthly',
            'currency' => 'PHP',
            'status' => 'draft',
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function item(array $changes = []): array
    {
        return array_replace([
            'code' => 'BASIC',
            'name' => 'Basic salary',
            'type' => 'earning',
            'amount' => '1000.00',
        ], $changes);
    }
}
