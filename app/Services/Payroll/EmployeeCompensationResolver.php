<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeeCompensation;
use Carbon\CarbonInterface;
use RuntimeException;

class EmployeeCompensationResolver
{
    public function resolve(
        Employee $employee,
        CarbonInterface|string $referenceDate
    ): EmployeeCompensation {
        $date = $referenceDate instanceof CarbonInterface
            ? $referenceDate->toDateString()
            : $referenceDate;

        $compensation = $employee->compensations()
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date) {
                $query
                    ->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if (! $compensation) {
            throw new RuntimeException(
                'No effective compensation exists for this employee '
                ."on {$date}."
            );
        }

        return $compensation;
    }
}
