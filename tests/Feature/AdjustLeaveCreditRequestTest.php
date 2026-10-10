<?php

namespace Tests\Feature;

use App\Http\Requests\AdjustLeaveCreditRequest;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class AdjustLeaveCreditRequestTest extends FormRequestTestCase
{
    #[DataProvider('validUnits')]
    public function test_accepts_signed_adjustments_without_requiring_remarks(string $units): void
    {
        $actor = $this->user('leave.manage-credits');
        $employee = $this->employee();
        $leaveType = $this->leaveType();

        if (str_starts_with($units, '-')) {
            LeaveBalance::query()->create([
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'year' => 2026,
                'balance' => '99999999.99',
            ]);
        }

        $validated = $this->validateRequest(
            AdjustLeaveCreditRequest::class,
            $this->payload($employee, $leaveType, ['units' => $units]),
            $actor,
        );

        $this->assertSame($units, $validated['units']);
        $this->assertArrayNotHasKey('remarks', $validated);
    }

    public static function validUnits(): array
    {
        return [
            'minimum credit' => ['0.01'],
            'minimum debit' => ['-0.01'],
            'whole credit' => ['15'],
            'fractional debit' => ['-2.75'],
            'padded nonzero credit' => ['0001.20'],
            'largest credit transaction' => ['99999999.99'],
            'largest debit transaction' => ['-99999999.99'],
        ];
    }

    public function test_only_returns_adjustment_inputs_and_preserves_optional_remarks(): void
    {
        $actor = $this->user('leave.manage-credits');
        $employee = $this->employee();
        $leaveType = $this->leaveType();

        $validated = $this->validateRequest(
            AdjustLeaveCreditRequest::class,
            $this->payload($employee, $leaveType, [
                'remarks' => 'Opening balance correction.',
                'created_by_user_id' => 999999,
                'year' => 1900,
                'balance' => '5000.00',
                'transaction_type' => 'forged_credit',
                'leave_request_id' => 999999,
            ]),
            $actor,
        );

        $this->assertSame([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'units' => '1.25',
            'effective_date' => '2026-10-03',
            'remarks' => 'Opening balance correction.',
        ], $validated);
    }

    public function test_accepts_null_remarks_and_balance_managed_inactive_leave_type(): void
    {
        $actor = $this->user('leave.manage-credits');
        $employee = $this->employee();
        $leaveType = $this->leaveType(['is_active' => false]);

        $validated = $this->validateRequest(
            AdjustLeaveCreditRequest::class,
            $this->payload($employee, $leaveType, ['remarks' => null]),
            $actor,
        );

        $this->assertNull($validated['remarks']);
    }

    #[DataProvider('invalidInput')]
    public function test_rejects_invalid_adjustment_input(array $changes, array $fields): void
    {
        $actor = $this->user('leave.manage-credits');
        $employee = $this->employee();
        $leaveType = $this->leaveType();

        $this->assertInvalidRequest(
            AdjustLeaveCreditRequest::class,
            $this->payload($employee, $leaveType, $changes),
            $actor,
            [],
            $fields,
        );
    }

    public static function invalidInput(): array
    {
        return [
            'missing employee' => [['employee_id' => null], ['employee_id']],
            'unknown employee' => [['employee_id' => 999999], ['employee_id']],
            'employee is an array' => [['employee_id' => ['id' => 1]], ['employee_id']],
            'employee is a boolean' => [['employee_id' => true], ['employee_id']],
            'missing leave type' => [['leave_type_id' => null], ['leave_type_id']],
            'unknown leave type' => [['leave_type_id' => 999999], ['leave_type_id']],
            'leave type is an array' => [['leave_type_id' => [1]], ['leave_type_id']],
            'leave type is a boolean' => [['leave_type_id' => true], ['leave_type_id']],
            'missing units' => [['units' => null], ['units']],
            'empty units' => [['units' => ''], ['units']],
            'zero integer' => [['units' => 0], ['units']],
            'zero decimal' => [['units' => '0.00'], ['units']],
            'padded zero' => [['units' => '000.00'], ['units']],
            'negative padded zero' => [['units' => '-000.00'], ['units']],
            'too much precision' => [['units' => '1.001'], ['units']],
            'negative too much precision' => [['units' => '-1.001'], ['units']],
            'positive overflow' => [['units' => '100000000.00'], ['units']],
            'negative overflow' => [['units' => '-100000000.00'], ['units']],
            'exponent text' => [['units' => '1e2'], ['units']],
            'comma decimal' => [['units' => '1,25'], ['units']],
            'malformed number' => [['units' => 'one'], ['units']],
            'units are an array' => [['units' => ['1.25']], ['units']],
            'units are a boolean' => [['units' => true], ['units']],
            'missing effective date' => [['effective_date' => null], ['effective_date']],
            'impossible effective date' => [['effective_date' => '2026-02-30'], ['effective_date']],
            'date is not a date' => [['effective_date' => 'tomorrow-ish'], ['effective_date']],
            'date is an array' => [['effective_date' => ['2026-10-03']], ['effective_date']],
            'remarks are an array' => [['remarks' => ['Correction']], ['remarks']],
        ];
    }

    public function test_rejects_credit_adjustments_for_leave_types_without_balances(): void
    {
        $actor = $this->user('leave.manage-credits');
        $employee = $this->employee();
        $leaveType = $this->leaveType(['requires_balance' => false]);

        $this->assertInvalidRequest(
            AdjustLeaveCreditRequest::class,
            $this->payload($employee, $leaveType),
            $actor,
            [],
            ['leave_type_id'],
        );
    }

    public function test_rejects_an_actor_without_credit_management_permission(): void
    {
        $actor = $this->user('leave.view');
        $employee = $this->employee();
        $leaveType = $this->leaveType();

        $this->assertUnauthorizedRequest(
            AdjustLeaveCreditRequest::class,
            $this->payload($employee, $leaveType),
            $actor,
        );
    }

    public function test_rejects_an_inactive_actor_even_with_credit_management_permission(): void
    {
        $actor = $this->user('leave.manage-credits');
        $actor->update(['is_active' => false]);
        $employee = $this->employee();
        $leaveType = $this->leaveType();

        $this->assertUnauthorizedRequest(
            AdjustLeaveCreditRequest::class,
            $this->payload($employee, $leaveType),
            $actor,
        );
    }

    public function test_rejects_an_unauthenticated_request(): void
    {
        $employee = $this->employee();
        $leaveType = $this->leaveType();

        $this->assertUnauthorizedRequest(
            AdjustLeaveCreditRequest::class,
            $this->payload($employee, $leaveType),
            null,
        );
    }

    public function test_temporary_runtime_fixture_is_removed_after_explicit_rollback(): void
    {
        $actor = $this->user('leave.manage-credits');
        $leaveType = $this->leaveType();
        $startingLevel = DB::transactionLevel();
        $employeeNumber = 'ROLLBACK-'.Str::uuid();

        DB::beginTransaction();

        try {
            $employee = $this->employee(attributes: ['employee_number' => $employeeNumber]);

            $validated = $this->validateRequest(
                AdjustLeaveCreditRequest::class,
                $this->payload($employee, $leaveType),
                $actor,
            );

            $this->assertSame($employee->id, $validated['employee_id']);
        } finally {
            DB::rollBack($startingLevel);
        }

        $this->assertSame($startingLevel, DB::transactionLevel());
        $this->assertDatabaseMissing('employees', ['employee_number' => $employeeNumber]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function leaveType(array $attributes = []): LeaveType
    {
        return LeaveType::query()->create(array_replace([
            'code' => 'TEST-'.Str::uuid(),
            'name' => 'Balance-managed leave',
            'requires_balance' => true,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function payload(Employee $employee, LeaveType $leaveType, array $changes = []): array
    {
        return array_replace([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'units' => '1.25',
            'effective_date' => '2026-10-03',
        ], $changes);
    }
}
