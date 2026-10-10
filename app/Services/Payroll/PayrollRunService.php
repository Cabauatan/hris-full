<?php

namespace App\Services\Payroll;

use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PayrollRunService
{
    private function ensureStatus(
        PayrollRun $run,
        string $expectedStatus
    ): void {
        if ($run->status !== $expectedStatus) {
            throw new RuntimeException(
                "Payroll run must be {$expectedStatus}. "
                ."Current status: {$run->status}."
            );
        }
    }

    public function start(PayrollRun $run): PayrollRun
    {
        return DB::transaction(function () use ($run) {
            $run = PayrollRun::query()
                ->lockForUpdate()
                ->findOrFail($run->id);

            $this->ensureStatus($run, 'draft');

            $run->update([
                'status' => 'processing',
                'started_at' => now(),
                'completed_at' => null,
                'finalized_at' => null,
                'finalized_by_user_id' => null,
            ]);

            return $run->fresh([
                'payrollPeriod',
            ]);
        });
    }

    public function complete(PayrollRun $run): PayrollRun
    {
        return DB::transaction(function () use ($run) {
            $run = PayrollRun::query()
                ->lockForUpdate()
                ->findOrFail($run->id);

            $this->ensureStatus($run, 'processing');

            $run->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            return $run->fresh([
                'payrollPeriod',
            ]);
        });
    }

    public function finalize(
        PayrollRun $run,
        User $finalizedBy
    ): PayrollRun {
        return DB::transaction(function () use ($run, $finalizedBy) {
            $run = PayrollRun::query()
                ->lockForUpdate()
                ->findOrFail($run->id);

            $this->ensureStatus($run, 'completed');

            $run->update([
                'status' => 'finalized',
                'finalized_at' => now(),
                'finalized_by_user_id' => $finalizedBy->id,
            ]);

            return $run->fresh([
                'payrollPeriod',
                'finalizer',
            ]);
        });
    }
}
