<?php

namespace App\Services\Payslip;

use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class PayslipDocumentService
{
    public function __construct(private PayslipService $payslips) {}

    public function generate(PayrollEntry $entry, User $actor): Payslip
    {
        $runId = PayrollEntry::query()->findOrFail($entry->id)->payroll_run_id;
        $path = null;

        try {
            return DB::transaction(function () use ($entry, $actor, $runId, &$path): Payslip {
                $run = PayrollRun::query()->lockForUpdate()->findOrFail($runId);
                $entry = PayrollEntry::query()->where('payroll_run_id', $run->id)->lockForUpdate()->findOrFail($entry->id);
                $entry->setRelation('payrollRun', $run->load('payrollPeriod'));
                $entry->load(['employee', 'items']);
                $path = 'payslips/'.$entry->id.'/'.Str::uuid().'.html';
                $html = view('payslips.document', ['entry' => $entry])->render();

                abort_unless(Storage::disk('local')->put($path, $html), 500, 'Unable to store the payslip document.');

                return $this->payslips->registerGenerated($entry, $actor, $path);
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }

            throw $exception;
        }
    }

    public function ownsPath(?string $path): bool
    {
        return is_string($path)
            && str_starts_with($path, 'payslips/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\');
    }
}
