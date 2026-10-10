<?php

namespace Tests\Feature;

use App\Http\Requests\AdjustCtoCreditRequest;
use App\Models\CtoBalance;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class AdjustCtoCreditRequestTest extends FormRequestTestCase
{
    #[DataProvider('validMinutes')]
    public function test_accepts_signed_minutes_without_requiring_remarks(int $minutes): void
    {
        $actor = $this->user('cto.manage-credits');
        $employee = $this->employee();

        if ($minutes < 0) {
            CtoBalance::query()->create([
                'employee_id' => $employee->id,
                'balance_minutes' => 2147483648,
            ]);
        }

        $validated = $this->validateRequest(
            AdjustCtoCreditRequest::class,
            $this->payload($employee, ['minutes' => $minutes]),
            $actor,
        );

        $this->assertSame($minutes, $validated['minutes']);
        $this->assertArrayNotHasKey('remarks', $validated);
    }

    public static function validMinutes(): array
    {
        return [
            'minimum credit' => [1],
            'minimum debit' => [-1],
            'one hour credit' => [60],
            'one hour debit' => [-60],
            'signed integer maximum' => [2147483647],
            'signed integer minimum' => [-2147483648],
        ];
    }

    public function test_only_returns_adjustment_inputs_and_preserves_optional_remarks(): void
    {
        $actor = $this->user('cto.manage-credits');
        $employee = $this->employee();

        $validated = $this->validateRequest(
            AdjustCtoCreditRequest::class,
            $this->payload($employee, [
                'remarks' => 'Correction to earned credits.',
                'balance_minutes' => 99999,
                'created_by_user_id' => 999999,
                'transaction_type' => 'forged_credit',
                'overtime_request_id' => 999999,
                'cto_request_id' => 999999,
                'expires_at' => '2099-12-31',
            ]),
            $actor,
        );

        $this->assertSame([
            'employee_id' => $employee->id,
            'minutes' => 60,
            'effective_date' => '2026-10-03',
            'remarks' => 'Correction to earned credits.',
        ], $validated);
    }

    public function test_accepts_null_remarks(): void
    {
        $actor = $this->user('cto.manage-credits');
        $employee = $this->employee();

        $validated = $this->validateRequest(
            AdjustCtoCreditRequest::class,
            $this->payload($employee, ['remarks' => null]),
            $actor,
        );

        $this->assertNull($validated['remarks']);
    }

    #[DataProvider('invalidInput')]
    public function test_rejects_invalid_adjustment_input(array $changes, array $fields): void
    {
        $actor = $this->user('cto.manage-credits');
        $employee = $this->employee();

        $this->assertInvalidRequest(
            AdjustCtoCreditRequest::class,
            $this->payload($employee, $changes),
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
            'missing minutes' => [['minutes' => null], ['minutes']],
            'empty minutes' => [['minutes' => ''], ['minutes']],
            'zero minutes' => [['minutes' => 0], ['minutes']],
            'zero string' => [['minutes' => '0'], ['minutes']],
            'padded zero' => [['minutes' => '000'], ['minutes']],
            'negative zero' => [['minutes' => '-0'], ['minutes']],
            'positive overflow' => [['minutes' => 2147483648], ['minutes']],
            'negative overflow' => [['minutes' => -2147483649], ['minutes']],
            'fractional minutes' => [['minutes' => 1.5], ['minutes']],
            'decimal string' => [['minutes' => '60.5'], ['minutes']],
            'malformed minutes' => [['minutes' => 'one hour'], ['minutes']],
            'minutes are an array' => [['minutes' => [60]], ['minutes']],
            'minutes are a boolean' => [['minutes' => true], ['minutes']],
            'missing effective date' => [['effective_date' => null], ['effective_date']],
            'impossible effective date' => [['effective_date' => '2026-02-30'], ['effective_date']],
            'date is not a date' => [['effective_date' => 'tomorrow-ish'], ['effective_date']],
            'date is an array' => [['effective_date' => ['2026-10-03']], ['effective_date']],
            'remarks are an array' => [['remarks' => ['Correction']], ['remarks']],
        ];
    }

    public function test_rejects_an_actor_without_credit_management_permission(): void
    {
        $actor = $this->user('cto.view');
        $employee = $this->employee();

        $this->assertUnauthorizedRequest(
            AdjustCtoCreditRequest::class,
            $this->payload($employee),
            $actor,
        );
    }

    public function test_rejects_an_inactive_actor_even_with_credit_management_permission(): void
    {
        $actor = $this->user('cto.manage-credits');
        $actor->update(['is_active' => false]);
        $employee = $this->employee();

        $this->assertUnauthorizedRequest(
            AdjustCtoCreditRequest::class,
            $this->payload($employee),
            $actor,
        );
    }

    public function test_rejects_an_unauthenticated_request(): void
    {
        $employee = $this->employee();

        $this->assertUnauthorizedRequest(
            AdjustCtoCreditRequest::class,
            $this->payload($employee),
            null,
        );
    }

    public function test_temporary_runtime_fixture_is_removed_after_explicit_rollback(): void
    {
        $actor = $this->user('cto.manage-credits');
        $startingLevel = DB::transactionLevel();
        $employeeNumber = 'ROLLBACK-'.Str::uuid();

        DB::beginTransaction();

        try {
            $employee = $this->employee(attributes: ['employee_number' => $employeeNumber]);

            $validated = $this->validateRequest(
                AdjustCtoCreditRequest::class,
                $this->payload($employee),
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
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function payload(Employee $employee, array $changes = []): array
    {
        return array_replace([
            'employee_id' => $employee->id,
            'minutes' => 60,
            'effective_date' => '2026-10-03',
        ], $changes);
    }
}
