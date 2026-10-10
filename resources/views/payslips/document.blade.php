<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payslip — {{ $entry->employee->employee_number }}</title>
    <style>
        body { font-family: Arial, sans-serif; color: #1f2937; max-width: 800px; margin: 40px auto; padding: 0 24px; }
        h1 { margin-bottom: 24px; }
        table { border-collapse: collapse; width: 100%; margin: 24px 0; }
        th, td { padding: 10px; border-bottom: 1px solid #d1d5db; text-align: left; }
        .amount { text-align: right; font-variant-numeric: tabular-nums; }
        .totals { max-width: 420px; margin-left: auto; }
        @media print { body { margin: 0; } }
    </style>
</head>
<body>
    <h1>Payslip</h1>
    <p><strong>Employee:</strong> {{ $entry->employee->first_name }} {{ $entry->employee->middle_name }} {{ $entry->employee->last_name }} {{ $entry->employee->suffix }}</p>
    <p><strong>Employee number:</strong> {{ $entry->employee->employee_number }}</p>
    <p><strong>Payroll period:</strong> {{ $entry->payrollRun->payrollPeriod->code }}
        ({{ $entry->payrollRun->payrollPeriod->period_start->format('Y-m-d') }} to {{ $entry->payrollRun->payrollPeriod->period_end->format('Y-m-d') }})</p>
    <p><strong>Pay date:</strong> {{ $entry->payrollRun->payrollPeriod->pay_date?->format('Y-m-d') }}</p>
    <p><strong>Currency:</strong> {{ $entry->currency }}</p>
    <table>
        <thead><tr><th>Description</th><th>Type</th><th class="amount">Amount</th></tr></thead>
        <tbody>
        @forelse ($entry->items as $item)
            <tr><td>{{ $item->name }}</td><td>{{ ucfirst($item->type) }}</td><td class="amount">{{ $item->amount }}</td></tr>
        @empty
            <tr><td colspan="3">No payroll items.</td></tr>
        @endforelse
        </tbody>
    </table>
    <table class="totals">
        <tbody>
        <tr><th>Gross pay</th><td class="amount">{{ $entry->gross_pay }}</td></tr>
        <tr><th>Total deductions</th><td class="amount">{{ $entry->total_deductions }}</td></tr>
        <tr><th>Net pay</th><td class="amount"><strong>{{ $entry->net_pay }}</strong></td></tr>
        </tbody>
    </table>
</body>
</html>
