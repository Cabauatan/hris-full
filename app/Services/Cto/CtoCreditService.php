<?php

namespace App\Services\Cto;

use App\Models\CtoBalance;
use App\Models\CtoCreditTransaction;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CtoCreditService
{
    public function adjust(
        Employee $employee,
        int $minutes,
        CarbonInterface|string $effectiveDate,
        User $actor,
        ?string $remarks = null
    ): CtoCreditTransaction {
        return DB::transaction(function () use (
            $employee,
            $minutes,
            $effectiveDate,
            $actor,
            $remarks
        ) {
            if ($minutes === 0) {
                throw new RuntimeException(
                    'CTO credit adjustment cannot be zero.'
                );
            }

            $date = $effectiveDate instanceof CarbonInterface
                ? $effectiveDate->copy()
                : Carbon::parse($effectiveDate);

            // Serialize balance creation/adjustments for this employee.
            Employee::query()
                ->whereKey($employee->id)
                ->lockForUpdate()
                ->firstOrFail();

            $balance = CtoBalance::query()
                ->where('employee_id', $employee->id)
                ->lockForUpdate()
                ->first();

            if (! $balance) {
                if ($minutes < 0) {
                    throw new RuntimeException(
                        'Cannot deduct CTO credits because no balance exists.'
                    );
                }

                $balance = CtoBalance::create([
                    'employee_id' => $employee->id,
                    'balance_minutes' => 0,
                ]);
            }

            $newBalance = $balance->balance_minutes + $minutes;

            if ($newBalance < 0) {
                throw new RuntimeException(
                    'CTO credit adjustment cannot make the balance negative.'
                );
            }

            if ($newBalance > 4_294_967_295) {
                throw new RuntimeException('Cto credit adjustment exceeds the supported balance.');
            }

            $balance->update([
                'balance_minutes' => $newBalance,
            ]);

            $transaction = CtoCreditTransaction::create([
                'employee_id' => $employee->id,
                'overtime_request_id' => null,
                'cto_request_id' => null,
                'minutes' => $minutes,
                'transaction_type' => $minutes > 0
                    ? 'manual_credit'
                    : 'manual_debit',
                'effective_date' => $date->toDateString(),
                'expires_at' => null,
                'remarks' => $remarks,
                'created_by_user_id' => $actor->id,
            ]);

            return $transaction->fresh([
                'employee',
                'creator',
            ]);
        });
    }
}
