<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CtoBalance;
use App\Models\CtoCreditTransaction;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\LeaveBalance;
use App\Models\LeaveCreditTransaction;
use App\Models\LeaveType;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\Audit\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class PayrollApiTest extends FormRequestTestCase
{
    #[DataProvider('readEndpoints')]
    public function test_finance_lists_require_authentication_and_the_module_permission(string $path): void
    {
        $viewer = $this->user();

        $this->getJson('/api/v1/'.$path)->assertUnauthorized();
        $this->actingAs($viewer)->getJson('/api/v1/'.$path)->assertForbidden();
    }

    public static function readEndpoints(): array
    {
        return [
            'payroll periods' => ['payroll-periods'],
            'payroll runs' => ['payroll-runs'],
            'payroll entries' => ['payroll-entries'],
            'payroll entry items' => ['payroll-entry-items'],
            'payslips' => ['payslips'],
            'leave balances' => ['leave-balances'],
            'leave credit transactions' => ['leave-credit-transactions'],
            'CTO balances' => ['cto-balances'],
            'CTO credit transactions' => ['cto-credit-transactions'],
            'audit logs' => ['audit-logs'],
        ];
    }

    public function test_payroll_period_create_and_update_preserve_date_only_resource_fields(): void
    {
        $processor = $this->user('payroll.process', 'payroll.view');
        $payload = $this->periodPayload();

        $created = $this->actingAs($processor)->postJson('/api/v1/payroll-periods', $payload)
            ->assertCreated()
            ->assertJsonPath('data.period_start', '2026-10-01')
            ->assertJsonPath('data.period_end', '2026-10-31')
            ->assertJsonPath('data.pay_date', '2026-11-05');
        $id = $created->json('data.id');

        $this->putJson('/api/v1/payroll-periods/'.$id, array_replace($payload, ['pay_date' => '2026-11-06']))
            ->assertOk()
            ->assertJsonPath('data.pay_date', '2026-11-06');

        $this->assertSame('2026-11-06', PayrollPeriod::query()->findOrFail($id)->pay_date->toDateString());
    }

    public function test_payroll_run_creation_uses_draft_status_and_authenticated_creator(): void
    {
        $processor = $this->user('payroll.process');
        $period = $this->period();

        $response = $this->actingAs($processor)->postJson('/api/v1/payroll-runs', [
            'payroll_period_id' => $period->id,
            'run_number' => 1,
            'remarks' => 'Initial run.',
            'status' => 'finalized',
            'created_by_user_id' => 999999,
            'finalized_by_user_id' => 999999,
            'finalized_at' => '1900-01-01 00:00:00',
        ])->assertCreated()->assertJsonPath('data.status', 'draft');

        $run = PayrollRun::query()->findOrFail($response->json('data.id'));
        $this->assertSame($processor->id, $run->created_by_user_id);
        $this->assertNull($run->finalized_at);
        $this->assertNull($run->finalized_by_user_id);
    }

    public function test_payroll_run_creation_reports_composite_uniqueness_as_validation_error(): void
    {
        $processor = $this->user('payroll.process');
        $run = $this->payrollRun();

        $this->actingAs($processor)->postJson('/api/v1/payroll-runs', [
            'payroll_period_id' => $run->payroll_period_id,
            'run_number' => $run->run_number,
        ])->assertUnprocessable()->assertJsonValidationErrors('run_number');

        $this->assertDatabaseCount('payroll_runs', 1);
    }

    public function test_payroll_run_actions_execute_real_transitions_and_record_the_finalizer(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 09:00:00'));
        $processor = $this->user('payroll.process');
        $finalizer = $this->user('payroll.finalize');
        $run = $this->payrollRun();

        $this->actingAs($processor)->postJson('/api/v1/payroll-runs/'.$run->id.'/start', ['status' => 'finalized'])
            ->assertOk()->assertJsonPath('data.status', 'processing');
        $this->assertSame('2026-10-03 09:00:00', $run->fresh()->started_at->format('Y-m-d H:i:s'));

        $this->postJson('/api/v1/payroll-runs/'.$run->id.'/complete')
            ->assertOk()->assertJsonPath('data.status', 'completed');

        $this->actingAs($finalizer)->postJson('/api/v1/payroll-runs/'.$run->id.'/finalize', ['finalized_by_user_id' => 999999])
            ->assertOk()->assertJsonPath('data.status', 'finalized');

        $run->refresh();
        $this->assertSame($finalizer->id, $run->finalized_by_user_id);
        $this->assertSame('2026-10-03 09:00:00', $run->completed_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-03 09:00:00', $run->finalized_at->format('Y-m-d H:i:s'));
    }

    public function test_repeating_a_payroll_transition_returns_conflict_without_changing_the_run(): void
    {
        $processor = $this->user('payroll.process');
        $run = $this->payrollRun(['status' => 'processing', 'started_at' => '2026-10-03 09:00:00']);

        $this->actingAs($processor)->postJson('/api/v1/payroll-runs/'.$run->id.'/start')->assertConflict();

        $run->refresh();
        $this->assertSame('processing', $run->status);
        $this->assertSame('2026-10-03 09:00:00', $run->started_at->format('Y-m-d H:i:s'));
    }

    public function test_payroll_process_permission_cannot_finalize_and_missing_run_binding_returns_not_found(): void
    {
        $processor = $this->user('payroll.process');
        $run = $this->payrollRun(['status' => 'completed']);

        $this->actingAs($processor)->postJson('/api/v1/payroll-runs/'.$run->id.'/finalize')->assertForbidden();
        $this->postJson('/api/v1/payroll-runs/999999/start')->assertNotFound();

        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_preparing_payroll_entry_resolves_effective_compensation_instead_of_client_snapshot(): void
    {
        $processor = $this->user('payroll.process');
        $employee = $this->employee();
        $run = $this->payrollRun();
        $this->compensation($employee, '12345.67', '2026-01-01');
        $this->compensation($employee, '20000.00', '2027-01-01');

        $response = $this->actingAs($processor)->postJson('/api/v1/payroll-runs/'.$run->id.'/prepare-entry', [
            'employee_id' => $employee->id,
            'compensation_reference_date' => '2026-10-03',
            'basic_salary' => '999999.00',
            'gross_pay' => '999999.00',
            'currency' => 'USD',
            'status' => 'finalized',
        ])->assertSuccessful()
            ->assertJsonPath('data.basic_salary', '12345.67')
            ->assertJsonPath('data.currency', 'PHP');

        $entry = PayrollEntry::query()->findOrFail($response->json('data.id'));
        $this->assertSame($run->id, $entry->payroll_run_id);
        $this->assertSame($employee->id, $entry->employee_id);
        $this->assertSame('12345.67', $entry->basic_salary);
        $this->assertSame('0.00', $entry->gross_pay);
        $this->assertSame('draft', $entry->status);
    }

    public function test_preparing_entry_without_effective_compensation_returns_conflict_without_creating_entry(): void
    {
        $processor = $this->user('payroll.process');
        $employee = $this->employee();
        $run = $this->payrollRun();

        $this->actingAs($processor)->postJson('/api/v1/payroll-runs/'.$run->id.'/prepare-entry', [
            'employee_id' => $employee->id,
            'compensation_reference_date' => '2026-10-03',
        ])->assertConflict();

        $this->assertDatabaseCount('payroll_entries', 0);
    }

    public function test_replacing_items_persists_exact_centavo_totals_and_removes_old_items(): void
    {
        $processor = $this->user('payroll.process');
        $entry = $this->entry();
        $oldItem = $entry->items()->create($this->item(['code' => 'OLD', 'amount' => '77.00']));
        $items = [
            $this->item(['code' => 'FIRST', 'amount' => '0.10']),
            $this->item(['code' => 'SECOND', 'amount' => '0.20']),
            $this->item(['code' => 'TAX', 'type' => 'deduction', 'amount' => '0.03']),
        ];

        $this->actingAs($processor)->putJson('/api/v1/payroll-entries/'.$entry->id.'/items', [
            'items' => $items,
            'gross_pay' => '999999.00',
            'net_pay' => '999999.00',
        ])->assertOk()
            ->assertJsonPath('data.gross_pay', '0.30')
            ->assertJsonPath('data.total_deductions', '0.03')
            ->assertJsonPath('data.net_pay', '0.27');

        $entry->refresh();
        $this->assertSame('0.30', $entry->gross_pay);
        $this->assertSame('0.03', $entry->total_deductions);
        $this->assertSame('0.27', $entry->net_pay);
        $this->assertNotNull($entry->calculated_at);
        $this->assertSame(3, $entry->items()->count());
        $this->assertModelMissing($oldItem);
    }

    public function test_empty_replacement_clears_payroll_items_and_resets_totals(): void
    {
        $processor = $this->user('payroll.process');
        $entry = $this->entry(attributes: ['gross_pay' => '77.00', 'net_pay' => '77.00']);
        $entry->items()->create($this->item(['amount' => '77.00']));

        $this->actingAs($processor)->putJson('/api/v1/payroll-entries/'.$entry->id.'/items', ['items' => []])
            ->assertOk()->assertJsonPath('data.net_pay', '0.00');

        $this->assertSame(0, $entry->items()->count());
        $this->assertSame('0.00', $entry->fresh()->gross_pay);
        $this->assertSame('0.00', $entry->fresh()->total_deductions);
    }

    public function test_item_relationship_injection_is_validation_failure_and_preserves_existing_payroll(): void
    {
        $processor = $this->user('payroll.process');
        $entry = $this->entry(attributes: ['gross_pay' => '77.00', 'net_pay' => '77.00']);
        $oldItem = $entry->items()->create($this->item(['amount' => '77.00']));

        $this->actingAs($processor)->putJson('/api/v1/payroll-entries/'.$entry->id.'/items', [
            'items' => [$this->item(['payroll_entry_id' => 999999])],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0');

        $this->assertModelExists($oldItem);
        $this->assertSame(1, $entry->items()->count());
        $this->assertSame('77.00', $entry->fresh()->net_pay);
    }

    public function test_completed_payroll_run_conflict_preserves_existing_items_and_totals(): void
    {
        $processor = $this->user('payroll.process');
        $run = $this->payrollRun(['status' => 'completed']);
        $entry = $this->entry($run, attributes: ['gross_pay' => '77.00', 'net_pay' => '77.00']);
        $oldItem = $entry->items()->create($this->item(['amount' => '77.00']));

        $this->actingAs($processor)->putJson('/api/v1/payroll-entries/'.$entry->id.'/items', [
            'items' => [$this->item(['amount' => '1.00'])],
        ])->assertConflict();

        $this->assertModelExists($oldItem);
        $this->assertSame('77.00', $entry->fresh()->net_pay);
    }

    public function test_leave_adjustments_create_signed_ledgers_and_rollback_insufficient_debit(): void
    {
        $manager = $this->user('leave.manage-credits');
        $employee = $this->employee();
        $leaveType = $this->leaveType();
        $payload = ['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'effective_date' => '2026-10-03'];

        $this->actingAs($manager)->postJson('/api/v1/leave-balances/adjust', $payload + [
            'units' => '2.50',
            'year' => 1900,
            'created_by_user_id' => 999999,
            'transaction_type' => 'forged',
        ])->assertCreated()->assertJsonPath('data.units', '2.50')->assertJsonPath('data.transaction_type', 'manual_credit');
        $this->postJson('/api/v1/leave-balances/adjust', $payload + ['units' => '-1.25'])
            ->assertCreated()->assertJsonPath('data.transaction_type', 'manual_debit');

        $balance = LeaveBalance::query()->firstOrFail();
        $this->assertSame('1.25', $balance->balance);
        $this->assertSame(2026, $balance->year);
        $this->assertSame($manager->id, LeaveCreditTransaction::query()->firstOrFail()->created_by_user_id);

        $this->postJson('/api/v1/leave-balances/adjust', $payload + ['units' => '-1.26'])->assertConflict();

        $this->assertSame('1.25', $balance->fresh()->balance);
        $this->assertDatabaseCount('leave_credit_transactions', 2);
    }

    public function test_cto_adjustments_create_signed_ledgers_and_rollback_insufficient_debit(): void
    {
        $manager = $this->user('cto.manage-credits');
        $employee = $this->employee();
        $payload = ['employee_id' => $employee->id, 'effective_date' => '2026-10-03'];

        $this->actingAs($manager)->postJson('/api/v1/cto-balances/adjust', $payload + [
            'minutes' => 120,
            'balance_minutes' => 99999,
            'created_by_user_id' => 999999,
            'expires_at' => '2099-12-31',
        ])->assertCreated()->assertJsonPath('data.minutes', 120)->assertJsonPath('data.transaction_type', 'manual_credit');
        $this->postJson('/api/v1/cto-balances/adjust', $payload + ['minutes' => -30])
            ->assertCreated()->assertJsonPath('data.transaction_type', 'manual_debit');

        $balance = CtoBalance::query()->firstOrFail();
        $transaction = CtoCreditTransaction::query()->firstOrFail();
        $this->assertSame(90, $balance->balance_minutes);
        $this->assertSame($manager->id, $transaction->created_by_user_id);
        $this->assertNull($transaction->expires_at);

        $this->postJson('/api/v1/cto-balances/adjust', $payload + ['minutes' => -91])->assertConflict();

        $this->assertSame(90, $balance->fresh()->balance_minutes);
        $this->assertDatabaseCount('cto_credit_transactions', 2);
    }

    public function test_debit_without_a_balance_leaves_no_created_balance_or_ledger(): void
    {
        $manager = $this->user('leave.manage-credits', 'cto.manage-credits');
        $employee = $this->employee();
        $leaveType = $this->leaveType();

        $this->actingAs($manager)->postJson('/api/v1/leave-balances/adjust', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'units' => '-0.01',
            'effective_date' => '2026-10-03',
        ])->assertConflict();
        $this->postJson('/api/v1/cto-balances/adjust', [
            'employee_id' => $employee->id,
            'minutes' => -1,
            'effective_date' => '2026-10-03',
        ])->assertConflict();

        $this->assertDatabaseCount('leave_balances', 0);
        $this->assertDatabaseCount('leave_credit_transactions', 0);
        $this->assertDatabaseCount('cto_balances', 0);
        $this->assertDatabaseCount('cto_credit_transactions', 0);
    }

    #[DataProvider('employeeScopedEndpoints')]
    public function test_balance_and_ledger_lists_show_only_own_employee_without_team_permission(string $path, string $permission): void
    {
        $viewer = $this->user($permission);
        $ownEmployee = $this->employee($viewer);
        $ownRecord = $this->employeeRecord($path, $ownEmployee);
        $otherRecord = $this->employeeRecord($path, $this->employee());

        $this->actingAs($viewer)->getJson('/api/v1/'.$path)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownRecord->id);
        $this->getJson('/api/v1/'.$path.'/'.$otherRecord->id)->assertForbidden();
    }

    #[DataProvider('employeeScopedEndpoints')]
    public function test_balance_and_ledger_team_scope_includes_own_and_direct_subordinates_only(string $path, string $permission): void
    {
        $viewer = $this->user($permission, 'employees.view-team');
        $supervisor = $this->employee($viewer);
        $this->employeeRecord($path, $supervisor);
        $subordinate = $this->employee(attributes: ['supervisor_employee_id' => $supervisor->id]);
        $teamRecord = $this->employeeRecord($path, $subordinate);
        $this->employeeRecord($path, $this->employee());

        $this->actingAs($viewer)->getJson('/api/v1/'.$path)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/'.$path.'/'.$teamRecord->id)->assertOk()->assertJsonPath('data.id', $teamRecord->id);
    }

    #[DataProvider('employeeScopedEndpoints')]
    public function test_balance_and_ledger_module_viewer_without_employee_gets_an_empty_list(string $path, string $permission): void
    {
        $viewer = $this->user($permission);
        $this->employeeRecord($path, $this->employee());

        $this->actingAs($viewer)->getJson('/api/v1/'.$path)->assertOk()->assertJsonCount(0, 'data');
    }

    public static function employeeScopedEndpoints(): array
    {
        return [
            'leave balances' => ['leave-balances', 'leave.view'],
            'leave transactions' => ['leave-credit-transactions', 'leave.view'],
            'CTO balances' => ['cto-balances', 'cto.view'],
            'CTO transactions' => ['cto-credit-transactions', 'cto.view'],
        ];
    }

    public function test_credit_balance_capacity_conflicts_preserve_balance_and_ledgers(): void
    {
        $manager = $this->user('leave.manage-credits', 'cto.manage-credits');
        $employee = $this->employee();
        $leaveType = $this->leaveType();
        $leaveBalance = LeaveBalance::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'year' => 2026,
            'balance' => '99999999.99',
        ]);
        $ctoBalance = CtoBalance::query()->create([
            'employee_id' => $employee->id,
            'balance_minutes' => 4294967295,
        ]);

        $this->actingAs($manager)->postJson('/api/v1/leave-balances/adjust', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'units' => '0.01',
            'effective_date' => '2026-10-03',
        ])->assertConflict();
        $this->postJson('/api/v1/cto-balances/adjust', [
            'employee_id' => $employee->id,
            'minutes' => 1,
            'effective_date' => '2026-10-03',
        ])->assertConflict();

        $this->assertSame('99999999.99', $leaveBalance->fresh()->balance);
        $this->assertSame(4294967295, $ctoBalance->fresh()->balance_minutes);
        $this->assertDatabaseCount('leave_credit_transactions', 0);
        $this->assertDatabaseCount('cto_credit_transactions', 0);
    }

    public function test_global_employee_view_permission_can_read_all_credit_records_without_employee_link(): void
    {
        $viewer = $this->user('leave.view', 'cto.view', 'employees.view');
        $employee = $this->employee();
        $this->employeeRecord('leave-balances', $employee);
        $this->employeeRecord('leave-credit-transactions', $employee);
        $this->employeeRecord('cto-balances', $employee);
        $this->employeeRecord('cto-credit-transactions', $employee);

        $this->actingAs($viewer)->getJson('/api/v1/leave-balances')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/leave-credit-transactions')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/cto-balances')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/cto-credit-transactions')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_payroll_view_permission_has_global_read_scope_without_exposing_related_user_models(): void
    {
        $viewer = $this->user('payroll.view');
        $entry = $this->entry(attributes: ['basic_salary' => '12345.67']);
        $this->entry();

        $this->actingAs($viewer)->getJson('/api/v1/payroll-entries')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/payroll-entries/'.$entry->id)
            ->assertOk()->assertJsonPath('data.basic_salary', '12345.67')
            ->assertJsonMissingPath('data.employee.user')
            ->assertJsonMissingPath('data.creator.password');
    }

    public function test_payroll_entry_item_read_resources_preserve_decimal_precision_without_related_profiles(): void
    {
        $viewer = $this->user('payroll.view');
        $owner = $this->user();
        $employee = $this->employee($owner, [
            'personal_email' => 'PRIVATE-PROFILE@example.test',
            'mobile_number' => 'PRIVATE-MOBILE',
            'birth_date' => '1990-01-01',
        ]);
        $entry = $this->entry(employee: $employee);
        $item = $entry->items()->create($this->item([
            'quantity' => '1.2500',
            'rate' => '100.1250',
            'amount' => '125.16',
            'remarks' => 'Payroll line snapshot.',
        ]));

        $index = $this->actingAs($viewer)->getJson('/api/v1/payroll-entry-items')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $item->id)
            ->assertJsonPath('data.0.quantity', '1.2500')
            ->assertJsonPath('data.0.rate', '100.1250')
            ->assertJsonPath('data.0.amount', '125.16')
            ->assertJsonMissingPath('data.0.payroll_entry')
            ->assertJsonMissingPath('data.0.employee')
            ->assertJsonMissingPath('data.0.user');
        $show = $this->getJson('/api/v1/payroll-entry-items/'.$item->id)
            ->assertOk()->assertJsonPath('data.payroll_entry_id', $entry->id)
            ->assertJsonPath('data.code', 'BASIC')
            ->assertJsonPath('data.type', 'earning')
            ->assertJsonPath('data.quantity', '1.2500')
            ->assertJsonPath('data.rate', '100.1250')
            ->assertJsonPath('data.amount', '125.16')
            ->assertJsonMissingPath('data.payroll_entry')
            ->assertJsonMissingPath('data.employee')
            ->assertJsonMissingPath('data.user');

        $this->assertStringNotContainsString('PRIVATE-', $index->getContent());
        $this->assertStringNotContainsString('PRIVATE-', $show->getContent());
    }

    public function test_finance_read_permissions_do_not_allow_payroll_or_credit_mutations(): void
    {
        $viewer = $this->user('payroll.view', 'leave.view', 'cto.view', 'payslips.view');
        $run = $this->payrollRun();
        $entry = $this->entry($run);

        $this->actingAs($viewer)->postJson('/api/v1/payroll-runs', [])->assertForbidden();
        $this->postJson('/api/v1/payroll-runs/'.$run->id.'/start')->assertForbidden();
        $this->putJson('/api/v1/payroll-entries/'.$entry->id.'/items', ['items' => []])->assertForbidden();
        $this->postJson('/api/v1/leave-balances/adjust', [])->assertForbidden();
        $this->postJson('/api/v1/cto-balances/adjust', [])->assertForbidden();
        $this->postJson('/api/v1/payroll-entries/'.$entry->id.'/generate-payslip')->assertForbidden();
    }

    public function test_payslip_list_only_exposes_owners_published_documents(): void
    {
        $owner = $this->user('payslips.view');
        $employee = $this->employee($owner);
        $published = $this->payslip($employee);
        $unpublished = $this->payslip($employee, ['published_at' => null]);
        $other = $this->payslip();

        $this->actingAs($owner)->getJson('/api/v1/payslips')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $published->id)
            ->assertJsonMissingPath('data.0.file_path');
        $this->getJson('/api/v1/payslips/'.$unpublished->id)->assertForbidden();
        $this->getJson('/api/v1/payslips/'.$other->id)->assertForbidden();
        $this->getJson('/api/v1/payslips/'.$other->id.'/download')->assertForbidden();
    }

    public function test_payslip_administrator_reads_drafts_but_cannot_mark_other_employees_view(): void
    {
        $administrator = $this->user('payslips.view', 'employees.view');
        $this->employee($administrator);
        $payslip = $this->payslip(attributes: ['published_at' => null]);

        $this->actingAs($administrator)->getJson('/api/v1/payslips')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/payslips/'.$payslip->id)->assertOk()->assertJsonMissingPath('data.file_path');
        $this->postJson('/api/v1/payslips/'.$payslip->id.'/mark-viewed')->assertForbidden();

        $this->assertNull($payslip->fresh()->viewed_at);
    }

    public function test_payslip_generation_uses_private_backend_document_and_cleans_failed_duplicate_artifact(): void
    {
        Storage::fake('local');
        $generator = $this->user('payslips.generate', 'payslips.publish');
        $owner = $this->user('payslips.view');
        $employee = $this->employee($owner, ['first_name' => '<script>owner</script>']);
        $run = $this->payrollRun(['status' => 'finalized', 'finalized_at' => '2026-10-03 09:00:00']);
        $entry = $this->entry($run, $employee, ['gross_pay' => '1000.00', 'net_pay' => '1000.00']);
        $entry->items()->create($this->item(['name' => '<script>alert(1)</script>']));

        $response = $this->actingAs($generator)->postJson('/api/v1/payroll-entries/'.$entry->id.'/generate-payslip', [
            'file_path' => '../../forged-document.html',
            'employee_id' => 999999,
            'generated_by_user_id' => 999999,
        ])->assertCreated()->assertJsonPath('data.employee_id', $employee->id)->assertJsonMissingPath('data.file_path');

        $payslip = Payslip::query()->findOrFail($response->json('data.id'));
        $this->assertSame($generator->id, $payslip->generated_by_user_id);
        $this->assertSame($entry->id, $payslip->payroll_entry_id);
        $this->assertStringStartsWith('payslips/', $payslip->file_path);
        $this->assertStringEndsWith('.html', $payslip->file_path);
        Storage::disk('local')->assertExists($payslip->file_path);
        $html = Storage::disk('local')->get($payslip->file_path);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);

        $this->postJson('/api/v1/payroll-entries/'.$entry->id.'/generate-payslip')->assertConflict();

        $this->assertDatabaseCount('payslips', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('payslips'));
        Storage::disk('local')->assertExists($payslip->file_path);

        $this->postJson('/api/v1/payslips/'.$payslip->id.'/publish', ['published_by_user_id' => 999999])->assertOk();

        $this->assertSame($generator->id, $payslip->fresh()->published_by_user_id);
        $download = $this->actingAs($owner)->get('/api/v1/payslips/'.$payslip->id.'/download')->assertOk()->assertDownload();
        $this->assertStringStartsWith('text/html', $download->headers->get('Content-Type'));
    }

    public function test_unfinalized_run_cannot_generate_payslip_and_leaves_no_document_or_database_row(): void
    {
        Storage::fake('local');
        $generator = $this->user('payslips.generate');
        $entry = $this->entry();

        $this->actingAs($generator)->postJson('/api/v1/payroll-entries/'.$entry->id.'/generate-payslip')->assertConflict();

        $this->assertDatabaseCount('payslips', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles('payslips'));
    }

    public function test_owner_mark_viewed_records_first_view_once_and_rejects_unpublished_document(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 09:00:00'));
        $owner = $this->user('payslips.view');
        $employee = $this->employee($owner);
        $payslip = $this->payslip($employee);
        $unpublished = $this->payslip($employee, ['published_at' => null]);

        $this->actingAs($owner)->postJson('/api/v1/payslips/'.$payslip->id.'/mark-viewed', ['viewed_at' => '1900-01-01 00:00:00'])->assertOk();
        $this->assertSame('2026-10-03 09:00:00', $payslip->fresh()->viewed_at->format('Y-m-d H:i:s'));
        $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00:00'));

        $this->postJson('/api/v1/payslips/'.$payslip->id.'/mark-viewed')->assertOk();
        $this->postJson('/api/v1/payslips/'.$unpublished->id.'/mark-viewed')->assertConflict();

        $this->assertSame('2026-10-03 09:00:00', $payslip->fresh()->viewed_at->format('Y-m-d H:i:s'));
        $this->assertNull($unpublished->fresh()->viewed_at);
    }

    public function test_audit_resource_preserves_server_redaction_without_expanding_related_models(): void
    {
        $auditor = $this->user('audit-logs.view');
        $employee = $this->employee();
        $audit = app(AuditLogService::class)->record(
            $auditor,
            'updated',
            $employee,
            ['password' => 'LEAKED-password'],
            ['first_name' => 'Changed'],
        );

        $this->actingAs($auditor)->getJson('/api/v1/audit-logs/'.$audit->id)
            ->assertOk()->assertJsonPath('data.old_values.password', '[REDACTED]')
            ->assertJsonPath('data.new_values.first_name', 'Changed')
            ->assertJsonMissingPath('data.user')
            ->assertJsonMissingPath('data.auditable');
    }

    public function test_audit_resource_redacts_legacy_credentials_and_request_url_secrets_at_read_boundary(): void
    {
        $auditor = $this->user('audit-logs.view');
        $audit = AuditLog::query()->create([
            'user_id' => $auditor->id,
            'action' => 'legacy_import',
            'old_values' => ['password' => 'LEAKED-password'],
            'new_values' => [
                'nested' => [
                    'api_key' => 'LEAKED-api',
                    'authorization' => 'Bearer LEAKED-bearer',
                    'privateKey' => 'LEAKED-private',
                    'normal' => 'visible',
                ],
            ],
            'request_url' => 'https://LEAKED-user:LEAKED-pass@example.test/api/v1/payroll-runs?token=LEAKED-query#LEAKED-fragment',
        ]);

        $response = $this->actingAs($auditor)->getJson('/api/v1/audit-logs/'.$audit->id)
            ->assertOk()->assertJsonPath('data.old_values.password', '[REDACTED]')
            ->assertJsonPath('data.new_values.nested.api_key', '[REDACTED]')
            ->assertJsonPath('data.new_values.nested.authorization', '[REDACTED]')
            ->assertJsonPath('data.new_values.nested.privateKey', '[REDACTED]')
            ->assertJsonPath('data.new_values.nested.normal', 'visible');

        $this->assertStringNotContainsString('LEAKED', $response->getContent());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function payslip(?Employee $employee = null, array $attributes = []): Payslip
    {
        $entry = $this->entry($this->payrollRun(['status' => 'finalized']), $employee);

        return Payslip::query()->create(array_replace([
            'payroll_entry_id' => $entry->id,
            'employee_id' => $entry->employee_id,
            'file_path' => 'payslips/test-'.Str::uuid().'.html',
            'generated_at' => '2026-10-03 08:00:00',
            'published_at' => '2026-10-03 08:30:00',
        ], $attributes));
    }

    private function period(): PayrollPeriod
    {
        return PayrollPeriod::query()->create($this->periodPayload());
    }

    /**
     * @return array<string, mixed>
     */
    private function periodPayload(): array
    {
        return [
            'code' => 'TEST-'.Str::uuid(),
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'pay_date' => '2026-11-05',
            'pay_frequency' => 'monthly',
            'status' => 'open',
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function payrollRun(array $attributes = []): PayrollRun
    {
        return PayrollRun::query()->create(array_replace([
            'payroll_period_id' => $this->period()->id,
            'run_number' => 1,
            'status' => 'draft',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function entry(?PayrollRun $run = null, ?Employee $employee = null, array $attributes = []): PayrollEntry
    {
        return PayrollEntry::query()->create(array_replace([
            'payroll_run_id' => ($run ?? $this->payrollRun())->id,
            'employee_id' => ($employee ?? $this->employee())->id,
            'basic_salary' => '10000.00',
            'pay_frequency' => 'monthly',
            'currency' => 'PHP',
            'status' => 'draft',
        ], $attributes));
    }

    private function compensation(Employee $employee, string $salary, string $effectiveFrom): EmployeeCompensation
    {
        return EmployeeCompensation::query()->create([
            'employee_id' => $employee->id,
            'basic_salary' => $salary,
            'pay_frequency' => 'monthly',
            'currency' => 'PHP',
            'effective_from' => $effectiveFrom,
        ]);
    }

    private function leaveType(): LeaveType
    {
        return LeaveType::query()->create([
            'code' => 'TEST-'.Str::uuid(),
            'name' => 'Balance-managed leave',
            'requires_balance' => true,
            'is_active' => true,
        ]);
    }

    private function employeeRecord(string $path, Employee $employee): Model
    {
        return match ($path) {
            'leave-balances' => LeaveBalance::query()->create([
                'employee_id' => $employee->id,
                'leave_type_id' => $this->leaveType()->id,
                'year' => 2026,
                'balance' => '1.25',
            ]),
            'leave-credit-transactions' => LeaveCreditTransaction::query()->create([
                'employee_id' => $employee->id,
                'leave_type_id' => $this->leaveType()->id,
                'year' => 2026,
                'units' => '1.25',
                'transaction_type' => 'manual_credit',
                'effective_date' => '2026-10-03',
            ]),
            'cto-balances' => CtoBalance::query()->create([
                'employee_id' => $employee->id,
                'balance_minutes' => 60,
            ]),
            'cto-credit-transactions' => CtoCreditTransaction::query()->create([
                'employee_id' => $employee->id,
                'minutes' => 60,
                'transaction_type' => 'manual_credit',
                'effective_date' => '2026-10-03',
            ]),
        };
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
