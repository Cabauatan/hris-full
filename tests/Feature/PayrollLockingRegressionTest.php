<?php

namespace Tests\Feature;

use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollEntryService;
use App\Services\Payslip\PayslipDocumentService;
use App\Services\Payslip\PayslipService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\FormRequestTestCase;

class PayrollLockingRegressionTest extends FormRequestTestCase
{
    public function test_item_replacement_checks_the_persisted_run_despite_an_editable_caller_relation(): void
    {
        $entry = $this->entry($this->payrollRun('finalized'));
        $oldItem = $entry->items()->create([
            'code' => 'OLD', 'name' => 'Existing earning', 'type' => 'earning', 'amount' => '1000.00',
        ]);
        $actualRunId = $entry->payroll_run_id;
        $editableRun = $this->payrollRun('draft');
        $entry->payroll_run_id = $editableRun->id;
        $entry->setRelation('payrollRun', $editableRun);

        try {
            $this->app->make(PayrollEntryService::class)->replaceItems($entry, []);
            $this->fail('Expected the persisted finalized run to prohibit item replacement.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('finalized', $exception->getMessage());
        }

        $entry->refresh();
        $this->assertSame($actualRunId, $entry->payroll_run_id);
        $this->assertSame('1000.00', $entry->gross_pay);
        $this->assertSame('1000.00', $entry->net_pay);
        $this->assertCount(1, $entry->items()->get());
        $this->assertModelExists($oldItem);
        $this->assertSame('draft', $editableRun->fresh()->status);
    }

    public function test_registration_checks_the_persisted_run_despite_a_finalized_caller_relation(): void
    {
        $entry = $this->entry($this->payrollRun('draft'));
        $actualRunId = $entry->payroll_run_id;
        $finalizedRun = $this->payrollRun('finalized');
        $entry->payroll_run_id = $finalizedRun->id;
        $entry->setRelation('payrollRun', $finalizedRun);

        try {
            $this->app->make(PayslipService::class)->registerGenerated($entry, $this->user(), 'payslips/prepared.html');
            $this->fail('Expected the persisted unfinalized run to prohibit registration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('draft', $exception->getMessage());
        }

        $this->assertDatabaseCount('payslips', 0);
        $this->assertSame($actualRunId, $entry->fresh()->payroll_run_id);
        $this->assertSame('finalized', $finalizedRun->fresh()->status);
    }

    public function test_document_generation_uses_the_persisted_finalized_entry_snapshot(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-10-06 09:00:00');
        $actor = $this->user();
        $realRun = $this->payrollRun('finalized');
        $entry = $this->entry($realRun);
        $actualEmployeeId = $entry->employee_id;
        $wrongRun = $this->payrollRun('draft');
        $entry->payroll_run_id = $wrongRun->id;
        $entry->gross_pay = '999999.99';
        $entry->net_pay = '999999.99';
        $entry->employee_id = $this->employee()->id;
        $entry->setRelation('payrollRun', $wrongRun);

        $payslip = $this->app->make(PayslipDocumentService::class)->generate($entry, $actor);

        $this->assertSame($entry->id, $payslip->payroll_entry_id);
        $this->assertSame($actualEmployeeId, $payslip->employee_id);
        $this->assertSame($actor->id, $payslip->generated_by_user_id);
        $this->assertSame('2026-10-06 09:00:00', $payslip->generated_at->format('Y-m-d H:i:s'));
        Storage::disk('local')->assertExists($payslip->file_path);
        $html = Storage::disk('local')->get($payslip->file_path);
        $this->assertStringContainsString($realRun->payrollPeriod->code, $html);
        $this->assertStringNotContainsString($wrongRun->payrollPeriod->code, $html);
        $this->assertStringContainsString('1000.00', $html);
        $this->assertStringNotContainsString('999999.99', $html);
        $this->assertDatabaseCount('payslips', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('payslips'));
        $this->assertSame($realRun->id, $entry->fresh()->payroll_run_id);
    }

    private function payrollRun(string $status): PayrollRun
    {
        $period = PayrollPeriod::query()->create([
            'code' => 'LOCK-'.Str::uuid(),
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'pay_date' => '2026-11-05',
            'pay_frequency' => 'monthly', 'status' => 'open',
        ]);

        return PayrollRun::query()->create([
            'payroll_period_id' => $period->id, 'run_number' => 1, 'status' => $status,
        ]);
    }

    private function entry(PayrollRun $run): PayrollEntry
    {
        return PayrollEntry::query()->create([
            'payroll_run_id' => $run->id, 'employee_id' => $this->employee()->id,
            'basic_salary' => '1000.00', 'pay_frequency' => 'monthly', 'currency' => 'PHP',
            'gross_pay' => '1000.00', 'total_deductions' => '0.00', 'net_pay' => '1000.00',
            'status' => 'draft',
        ]);
    }
}
