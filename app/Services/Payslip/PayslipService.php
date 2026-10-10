<?php

namespace App\Services\Payslip;

use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PayslipService
{
    public function __construct(private readonly NotificationService $notifications) {}

    private function ensurePayrollRunFinalized(
        PayrollRun $run
    ): void {
        if ($run->status !== 'finalized') {
            throw new RuntimeException(
                'Payslip generation requires a finalized payroll run. '
                ."Current status: {$run->status}."
            );
        }
    }

    public function registerGenerated(
        PayrollEntry $entry,
        User $generatedBy,
        string $filePath
    ): Payslip {
        $runId = PayrollEntry::query()->findOrFail($entry->id)->payroll_run_id;

        return DB::transaction(function () use (
            $entry,
            $generatedBy,
            $filePath,
            $runId
        ) {
            $run = PayrollRun::query()
                ->lockForUpdate()
                ->findOrFail($runId);

            $entry = PayrollEntry::query()
                ->where('payroll_run_id', $run->id)
                ->lockForUpdate()
                ->findOrFail($entry->id);

            $this->ensurePayrollRunFinalized($run);

            if ($entry->payslip()->exists()) {
                throw new RuntimeException(
                    'A payslip already exists for this payroll entry.'
                );
            }

            if (trim($filePath) === '') {
                throw new RuntimeException(
                    'Payslip file path must not be empty.'
                );
            }

            $payslip = Payslip::create([
                'payroll_entry_id' => $entry->id,
                'employee_id' => $entry->employee_id,
                'file_path' => $filePath,
                'generated_at' => now(),
                'generated_by_user_id' => $generatedBy->id,
            ]);

            return $payslip->fresh([
                'payrollEntry',
                'employee',
                'generator',
            ]);
        });
    }

    public function publish(
        Payslip $payslip,
        User $publishedBy
    ): Payslip {
        return DB::transaction(function () use (
            $payslip,
            $publishedBy
        ) {
            $payslip = Payslip::query()
                ->lockForUpdate()
                ->findOrFail($payslip->id);

            if (
                $payslip->generated_at === null
                || trim((string) $payslip->file_path) === ''
            ) {
                throw new RuntimeException(
                    'Payslip must be generated before it can be published.'
                );
            }

            if ($payslip->published_at !== null) {
                throw new RuntimeException(
                    'Payslip is already published.'
                );
            }

            $payslip->update([
                'published_at' => now(),
                'published_by_user_id' => $publishedBy->id,
            ]);

            $this->notifications->queue($payslip, 'published');

            return $payslip->fresh([
                'payrollEntry',
                'employee',
                'generator',
                'publisher',
            ]);
        });
    }

    public function markViewed(
        Payslip $payslip
    ): Payslip {
        return DB::transaction(function () use ($payslip) {
            $payslip = Payslip::query()
                ->lockForUpdate()
                ->findOrFail($payslip->id);

            if ($payslip->published_at === null) {
                throw new RuntimeException(
                    'Payslip must be published before it can be viewed.'
                );
            }

            if ($payslip->viewed_at === null) {
                $payslip->update([
                    'viewed_at' => now(),
                ]);
            }

            return $payslip->fresh([
                'payrollEntry',
                'employee',
                'generator',
                'publisher',
            ]);
        });
    }
}
