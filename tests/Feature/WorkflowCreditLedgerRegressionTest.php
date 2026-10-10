<?php

namespace Tests\Feature;

use App\Jobs\DeliverHrisNotification;
use App\Models\CtoBalance;
use App\Models\CtoRequest;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveCreditTransaction;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Cto\CtoService;
use App\Services\Leave\LeaveService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class WorkflowCreditLedgerRegressionTest extends FormRequestTestCase
{
    #[DataProvider('unavailableLeaveBalances')]
    public function test_returns_409_without_debiting_other_year_or_type_when_leave_balance_is_unavailable(?int $existingYear): void
    {
        [$reviewer, $owner, $employee] = $this->reviewFixture('leave');
        $leaveType = $this->leaveType();
        $request = $this->submitLeave($owner, $leaveType);
        $otherTypeBalance = $this->leaveBalance($employee, $this->leaveType(), 2026, '50.00');
        $otherEmployeeBalance = $this->leaveBalance($this->employee(), $leaveType, 2026, '50.00');
        $otherYearBalance = $existingYear === null ? null : $this->leaveBalance($employee, $leaveType, $existingYear, '50.00');
        Queue::fake([DeliverHrisNotification::class]);

        $this->actingAs($reviewer)->postJson('/api/v1/leave-requests/'.$request->id.'/approve', ['approved_units' => '0.25'])
            ->assertConflict()->assertJsonPath('message', 'No leave balance exists for the requested leave type and year.');

        $this->assertPendingWithoutReview($request);
        $this->assertSame('50.00', $otherTypeBalance->fresh()->balance);
        $this->assertSame('50.00', $otherEmployeeBalance->fresh()->balance);
        if ($otherYearBalance !== null) {
            $this->assertSame('50.00', $otherYearBalance->fresh()->balance);
        }
        $this->assertDatabaseCount('leave_credit_transactions', 0);
        $this->assertDatabaseCount('approval_actions', 0);
        Queue::assertNotPushed(DeliverHrisNotification::class);
    }

    /** @return array<string, array{?int}> */
    public static function unavailableLeaveBalances(): array
    {
        return ['no matching balance' => [null], 'credits belong to another year' => [2025]];
    }

    public function test_insufficient_leave_returns_409_then_approval_can_succeed_after_a_real_credit_adjustment(): void
    {
        [$reviewer, $owner, $employee] = $this->reviewFixture('leave');
        $manager = $this->user('leave.manage-credits');
        $leaveType = $this->leaveType();
        $balance = $this->leaveBalance($employee, $leaveType, 2026, '0.24');
        $request = $this->submitLeave($owner, $leaveType);
        Queue::fake([DeliverHrisNotification::class]);

        $this->actingAs($reviewer)->postJson('/api/v1/leave-requests/'.$request->id.'/approve', ['approved_units' => '0.25'])
            ->assertConflict()->assertJsonPath('message', 'Insufficient leave balance.');

        $this->assertPendingWithoutReview($request);
        $this->assertSame('0.24', $balance->fresh()->balance);
        $this->assertDatabaseCount('leave_credit_transactions', 0);
        $this->assertDatabaseCount('approval_actions', 0);
        Queue::assertNotPushed(DeliverHrisNotification::class);

        $this->actingAs($manager)->postJson('/api/v1/leave-balances/adjust', [
            'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
            'units' => '0.01', 'effective_date' => '2026-10-05',
        ])->assertCreated();
        Queue::fake([DeliverHrisNotification::class]);

        $this->actingAs($reviewer)->postJson('/api/v1/leave-requests/'.$request->id.'/approve', [
            'approved_units' => '0.25', 'remarks' => 'Credits are now available.',
        ])->assertOk()->assertJsonPath('data.approved_units', '0.25');

        $this->assertSame('0.00', $balance->fresh()->balance);
        $this->assertDatabaseCount('leave_credit_transactions', 2);
        $this->assertDatabaseHas('leave_credit_transactions', [
            'leave_request_id' => $request->id, 'units' => '-0.25',
            'year' => 2026, 'created_by_user_id' => $reviewer->id,
        ]);
        $this->assertSame('2026-10-03', LeaveCreditTransaction::query()->where('leave_request_id', $request->id)->sole()->effective_date->toDateString());
        $this->assertDatabaseHas('approval_actions', [
            'approvable_type' => $request->getMorphClass(), 'approvable_id' => $request->id,
            'action' => 'approved', 'acted_by_user_id' => $reviewer->id,
        ]);
        Queue::assertPushed(DeliverHrisNotification::class, fn (DeliverHrisNotification $job): bool => $job->resourceId === $request->id && $job->event === 'approved');
    }

    public function test_one_hundredth_leave_debit_uses_request_year_and_preserves_unrelated_balances(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00'));
        [$reviewer, $owner, $employee] = $this->reviewFixture('leave');
        $leaveType = $this->leaveType();
        $balance = $this->leaveBalance($employee, $leaveType, 2025, '0.01');
        $currentYearBalance = $this->leaveBalance($employee, $leaveType, 2026, '12.50');
        $otherEmployeeBalance = $this->leaveBalance($this->employee(), $leaveType, 2025, '5.00');
        $request = $this->submitLeave($owner, $leaveType, ['start_date' => '2025-12-31', 'end_date' => '2025-12-31', 'requested_units' => '0.01']);
        Queue::fake([DeliverHrisNotification::class]);

        $this->actingAs($reviewer)->postJson('/api/v1/leave-requests/'.$request->id.'/approve', ['approved_units' => '0.01'])
            ->assertOk()->assertJsonPath('data.approved_units', '0.01');

        $this->assertSame('0.00', $balance->fresh()->balance);
        $this->assertSame('12.50', $currentYearBalance->fresh()->balance);
        $this->assertSame('5.00', $otherEmployeeBalance->fresh()->balance);
        $this->assertDatabaseHas('leave_credit_transactions', [
            'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
            'leave_request_id' => $request->id, 'year' => 2025, 'units' => '-0.01',
        ]);
        $this->assertSame('2025-12-31', LeaveCreditTransaction::query()->where('leave_request_id', $request->id)->sole()->effective_date->toDateString());
        Queue::assertPushed(DeliverHrisNotification::class, 1);
    }

    public function test_balance_free_leave_can_be_approved_across_years_without_creating_a_credit_ledger(): void
    {
        [$reviewer, $owner] = $this->reviewFixture('leave');
        $leaveType = $this->leaveType(requiresBalance: false);
        $request = $this->submitLeave($owner, $leaveType, [
            'start_date' => '2026-12-31', 'end_date' => '2027-01-01', 'requested_units' => '2.00',
        ]);
        Queue::fake([DeliverHrisNotification::class]);

        $this->actingAs($reviewer)->postJson('/api/v1/leave-requests/'.$request->id.'/approve', ['approved_units' => '2.00'])
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseCount('leave_balances', 0);
        $this->assertDatabaseCount('leave_credit_transactions', 0);
        $this->assertDatabaseCount('approval_actions', 1);
        Queue::assertPushed(DeliverHrisNotification::class, 1);
    }

    #[DataProvider('approvedCreditTransitions')]
    public function test_returns_409_and_preserves_the_original_debit_when_an_approved_credit_request_is_reviewed_or_cancelled_again(string $module, string $transition): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00'));
        [$reviewer, $owner, $employee] = $this->reviewFixture($module);
        [$request, $balance] = $this->creditRequest($module, $owner, $employee);
        $approval = self::approval($module);
        $this->actingAs($reviewer)->postJson('/api/v1/'.$module.'-requests/'.$request->id.'/approve', $approval + ['remarks' => 'Original approval.'])
            ->assertOk();
        $approvedAt = $request->fresh()->reviewed_at->format('Y-m-d H:i:s');
        $ledgerTable = $module.'_credit_transactions';
        $ledgerId = DB::table($ledgerTable)->sole()->id;
        Queue::fake([DeliverHrisNotification::class]);

        $this->actingAs($transition === 'cancel' ? $owner : $reviewer)
            ->postJson('/api/v1/'.$module.'-requests/'.$request->id.'/'.$transition, $approval + ['remarks' => 'Overwrite.', 'reason' => 'Undo.'])
            ->assertConflict()->assertJsonPath('message', ($module === 'cto' ? 'CTO' : 'Leave').' request must be pending. Current status: approved.');

        $fresh = $request->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($reviewer->id, $fresh->reviewed_by_user_id);
        $this->assertSame('Original approval.', $fresh->review_remarks);
        $this->assertSame($approvedAt, $fresh->reviewed_at->format('Y-m-d H:i:s'));
        $this->assertNull($fresh->cancelled_at);
        $this->assertNull($fresh->cancellation_reason);
        $this->assertSame($module === 'leave' ? '0.00' : 0, $module === 'leave' ? $balance->fresh()->balance : $balance->fresh()->balance_minutes);
        $this->assertDatabaseCount($ledgerTable, 1);
        $this->assertSame($ledgerId, DB::table($ledgerTable)->sole()->id);
        $this->assertDatabaseCount('approval_actions', 1);
        Queue::assertNotPushed(DeliverHrisNotification::class);
    }

    /** @return array<string, array{string, string}> */
    public static function approvedCreditTransitions(): array
    {
        return [
            'leave approval retry' => ['leave', 'approve'],
            'leave approval followed by rejection' => ['leave', 'reject'],
            'leave approval followed by cancellation' => ['leave', 'cancel'],
            'CTO approval retry' => ['cto', 'approve'],
            'CTO approval followed by rejection' => ['cto', 'reject'],
            'CTO approval followed by cancellation' => ['cto', 'cancel'],
        ];
    }

    #[DataProvider('creditModules')]
    public function test_failed_ledger_insert_rolls_back_balance_and_review_without_recording_an_action_or_queueing_a_notification(string $module): void
    {
        [$reviewer, $owner, $employee] = $this->reviewFixture($module);
        [$request, $balance] = $this->creditRequest($module, $owner, $employee);
        Queue::fake([DeliverHrisNotification::class]);
        DB::statement('CREATE TRIGGER phase14_reject_credit_ledger BEFORE INSERT ON '.$module."_credit_transactions BEGIN SELECT RAISE(ABORT, 'phase14 ledger unavailable'); END");

        try {
            if ($module === 'leave') {
                app(LeaveService::class)->approve($request, $reviewer, '0.25');
            } else {
                app(CtoService::class)->approve($request, $reviewer, 30);
            }
            $this->fail('The injected ledger storage failure must abort the approval.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('phase14 ledger unavailable', $exception->getMessage());
        } finally {
            DB::statement('DROP TRIGGER phase14_reject_credit_ledger');
        }

        $this->assertPendingWithoutReview($request);
        $this->assertSame($module === 'leave' ? '0.25' : 30, $module === 'leave' ? $balance->fresh()->balance : $balance->fresh()->balance_minutes);
        $this->assertDatabaseCount($module.'_credit_transactions', 0);
        $this->assertDatabaseCount('approval_actions', 0);
        Queue::assertNotPushed(DeliverHrisNotification::class);
    }

    /** @return array<string, array{string}> */
    public static function creditModules(): array
    {
        return ['leave' => ['leave'], 'CTO' => ['cto']];
    }

    /** @return array{User, User, Employee} */
    private function reviewFixture(string $module): array
    {
        $reviewer = $this->user($module.'.approve');
        $supervisor = $this->employee($reviewer);
        $owner = $this->user($module.'.create');

        return [$reviewer, $owner, $this->employee($owner, ['supervisor_employee_id' => $supervisor->id])];
    }

    private function leaveType(bool $requiresBalance = true): LeaveType
    {
        return LeaveType::query()->create([
            'code' => 'LEAVE-'.Str::uuid(), 'name' => 'Regression leave', 'is_active' => true,
            'requires_balance' => $requiresBalance, 'allows_partial_day' => true, 'requires_document' => false,
        ]);
    }

    private function leaveBalance(Employee $employee, LeaveType $leaveType, int $year, string $balance): LeaveBalance
    {
        return LeaveBalance::query()->create([
            'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
            'year' => $year, 'balance' => $balance,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function submitLeave(User $owner, LeaveType $leaveType, array $overrides = []): LeaveRequest
    {
        Queue::fake([DeliverHrisNotification::class]);
        $response = $this->actingAs($owner)->postJson('/api/v1/leave-requests', array_replace([
            'leave_type_id' => $leaveType->id, 'start_date' => '2026-10-03', 'end_date' => '2026-10-03',
            'day_portion' => 'full_day', 'requested_units' => '0.25', 'reason' => 'Medical appointment.',
        ], $overrides))->assertCreated();

        return LeaveRequest::query()->findOrFail($response->json('data.id'));
    }

    /** @return array{LeaveRequest|CtoRequest, LeaveBalance|CtoBalance} */
    private function creditRequest(string $module, User $owner, Employee $employee): array
    {
        if ($module === 'leave') {
            $leaveType = $this->leaveType();
            $balance = $this->leaveBalance($employee, $leaveType, 2026, '0.25');

            return [$this->submitLeave($owner, $leaveType), $balance];
        }

        $balance = CtoBalance::query()->create(['employee_id' => $employee->id, 'balance_minutes' => 30]);
        Queue::fake([DeliverHrisNotification::class]);
        $response = $this->actingAs($owner)->postJson('/api/v1/cto-requests', [
            'start_at' => '2026-10-03 08:00:00', 'end_at' => '2026-10-03 08:30:00', 'reason' => 'Personal appointment.',
        ])->assertCreated();

        return [CtoRequest::query()->findOrFail($response->json('data.id')), $balance];
    }

    /** @return array<string, int|string> */
    private static function approval(string $module): array
    {
        return $module === 'leave' ? ['approved_units' => '0.25'] : ['approved_minutes' => 30];
    }

    private function assertPendingWithoutReview(Model $request): void
    {
        $fresh = $request->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->reviewed_by_user_id);
        $this->assertNull($fresh->reviewed_at);
        $this->assertNull($fresh->review_remarks);
        $this->assertNull($fresh instanceof LeaveRequest ? $fresh->approved_units : $fresh->approved_minutes);
    }
}
