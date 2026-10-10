<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PayrollEntryService
{
    public function __construct(
        private readonly EmployeeCompensationResolver $compensationResolver,
        private readonly PayrollEntryTotalsCalculator $totalsCalculator,
    ) {}

    private function ensureRunIsEditable(PayrollRun $run): void
    {
        if (! in_array($run->status, ['draft', 'processing'], true)) {
            throw new RuntimeException(
                'Payroll entries cannot be modified when payroll run '
                ."status is {$run->status}."
            );
        }
    }

    public function prepare(
        PayrollRun $run,
        Employee $employee,
        CarbonInterface|string $compensationReferenceDate
    ): PayrollEntry {
        return DB::transaction(function () use (
            $run,
            $employee,
            $compensationReferenceDate
        ) {
            $run = PayrollRun::query()
                ->lockForUpdate()
                ->findOrFail($run->id);

            $this->ensureRunIsEditable($run);

            $compensation = $this->compensationResolver->resolve(
                $employee,
                $compensationReferenceDate
            );

            $entry = PayrollEntry::query()->updateOrCreate(
                [
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->id,
                ],
                [
                    'basic_salary' => $compensation->basic_salary,
                    'pay_frequency' => $compensation->pay_frequency,
                    'currency' => $compensation->currency,
                ]
            );

            return $entry->fresh([
                'employee',
                'payrollRun',
                'items',
            ]);
        });
    }

    public function replaceItems(
        PayrollEntry $entry,
        array $items
    ): PayrollEntry {
        $runId = PayrollEntry::query()->findOrFail($entry->id)->payroll_run_id;

        return DB::transaction(function () use ($entry, $items, $runId) {
            $run = PayrollRun::query()
                ->lockForUpdate()
                ->findOrFail($runId);

            $entry = PayrollEntry::query()
                ->where('payroll_run_id', $run->id)
                ->lockForUpdate()
                ->findOrFail($entry->id);

            $this->ensureRunIsEditable($run);

            $entry->items()->delete();

            foreach ($items as $item) {
                $entry->items()->create($item);
            }

            $totals = $this->totalsCalculator->calculate($entry);

            $entry->update([
                'gross_pay' => $totals['gross_pay'],
                'total_deductions' => $totals['total_deductions'],
                'net_pay' => $totals['net_pay'],
                'calculated_at' => now(),
            ]);

            return $entry->fresh([
                'employee',
                'payrollRun',
                'items',
            ]);
        });
    }
}
