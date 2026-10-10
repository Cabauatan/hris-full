<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveCreditTransaction;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LeaveCreditService
{
    public function adjust(
        Employee $employee,
        LeaveType $leaveType,
        string $units,
        CarbonInterface|string $effectiveDate,
        User $actor,
        ?string $remarks = null
    ): LeaveCreditTransaction {
        return DB::transaction(function () use (
            $employee,
            $leaveType,
            $units,
            $effectiveDate,
            $actor,
            $remarks
        ) {
            if (! $leaveType->requires_balance) {
                throw new RuntimeException(
                    'Leave credits can only be managed for leave types that require a balance.'
                );
            }

            $adjustmentHundredths = $this->unitsToHundredths(
                $units,
                allowNegative: true
            );

            if ($adjustmentHundredths === 0) {
                throw new RuntimeException(
                    'Leave credit adjustment cannot be zero.'
                );
            }

            $date = $effectiveDate instanceof CarbonInterface
                ? $effectiveDate->copy()
                : Carbon::parse($effectiveDate);

            $year = $date->year;

            // Serialize balance adjustments for this employee.
            Employee::query()
                ->whereKey($employee->id)
                ->lockForUpdate()
                ->firstOrFail();

            $balance = LeaveBalance::query()
                ->where('employee_id', $employee->id)
                ->where('leave_type_id', $leaveType->id)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if (! $balance) {
                if ($adjustmentHundredths < 0) {
                    throw new RuntimeException(
                        'Cannot deduct leave credits because no balance exists.'
                    );
                }

                $balance = LeaveBalance::create([
                    'employee_id' => $employee->id,
                    'leave_type_id' => $leaveType->id,
                    'year' => $year,
                    'balance' => '0.00',
                ]);
            }

            $currentHundredths = $this->unitsToHundredths(
                $balance->balance
            );

            $newBalanceHundredths =
                $currentHundredths + $adjustmentHundredths;

            if ($newBalanceHundredths < 0) {
                throw new RuntimeException(
                    'Leave credit adjustment cannot make the balance negative.'
                );
            }

            if ($newBalanceHundredths > 9_999_999_999) {
                throw new RuntimeException('Leave credit adjustment exceeds the supported balance.');
            }

            $balance->update([
                'balance' => $this->hundredthsToUnits(
                    $newBalanceHundredths
                ),
            ]);

            $transaction = LeaveCreditTransaction::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'leave_request_id' => null,
                'year' => $year,
                'units' => $this->hundredthsToUnits(
                    $adjustmentHundredths
                ),
                'transaction_type' => $adjustmentHundredths > 0
                    ? 'manual_credit'
                    : 'manual_debit',
                'effective_date' => $date->toDateString(),
                'remarks' => $remarks,
                'created_by_user_id' => $actor->id,
            ]);

            return $transaction->fresh([
                'employee',
                'leaveType',
                'creator',
            ]);
        });
    }

    private function unitsToHundredths(
        string $units,
        bool $allowNegative = false
    ): int {
        $pattern = $allowNegative
            ? '/^-?\d+(?:\.\d{1,2})?$/'
            : '/^\d+(?:\.\d{1,2})?$/';

        if (! preg_match($pattern, $units)) {
            throw new RuntimeException(
                'Leave units must be a decimal with up to two decimal places.'
            );
        }

        $negative = str_starts_with($units, '-');

        if ($negative) {
            $units = substr($units, 1);
        }

        [$whole, $decimal] = array_pad(
            explode('.', $units, 2),
            2,
            '0'
        );

        $decimal = str_pad($decimal, 2, '0');

        $hundredths =
            ((int) $whole * 100)
            + (int) $decimal;

        return $negative
            ? -$hundredths
            : $hundredths;
    }

    private function hundredthsToUnits(int $hundredths): string
    {
        $negative = $hundredths < 0;
        $absolute = abs($hundredths);

        $units = sprintf(
            '%d.%02d',
            intdiv($absolute, 100),
            $absolute % 100
        );

        return $negative
            ? "-{$units}"
            : $units;
    }
}
