<?php

namespace App\Services\Payroll;

use App\Models\PayrollEntry;
use RuntimeException;

class PayrollEntryTotalsCalculator
{
    private function amountToCentavos(string $amount): int
    {
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $amount)) {
            throw new RuntimeException(
                "Invalid payroll amount: {$amount}."
            );
        }

        $negative = str_starts_with($amount, '-');

        $normalized = $negative
            ? substr($amount, 1)
            : $amount;

        [$whole, $decimal] = array_pad(
            explode('.', $normalized, 2),
            2,
            '0'
        );

        $decimal = str_pad($decimal, 2, '0');

        $centavos = ((int) $whole * 100) + (int) $decimal;

        return $negative ? -$centavos : $centavos;
    }

    private function centavosToAmount(int $centavos): string
    {
        $negative = $centavos < 0;
        $absolute = abs($centavos);

        $amount = sprintf(
            '%d.%02d',
            intdiv($absolute, 100),
            $absolute % 100
        );

        return $negative ? "-{$amount}" : $amount;
    }

    public function calculate(PayrollEntry $entry): array
    {
        $grossCentavos = 0;
        $deductionCentavos = 0;

        foreach ($entry->items()->get() as $item) {
            $amountCentavos = $this->amountToCentavos(
                $item->amount
            );

            if ($amountCentavos < 0) {
                throw new RuntimeException(
                    "Payroll item {$item->code} must not have a negative amount."
                );
            }

            match ($item->type) {
                'earning' => $grossCentavos += $amountCentavos,
                'deduction' => $deductionCentavos += $amountCentavos,
                default => throw new RuntimeException(
                    "Unsupported payroll item type: {$item->type}."
                ),
            };
        }

        return [
            'gross_pay' => $this->centavosToAmount($grossCentavos),
            'total_deductions' => $this->centavosToAmount(
                $deductionCentavos
            ),
            'net_pay' => $this->centavosToAmount(
                $grossCentavos - $deductionCentavos
            ),
        ];
    }
}
