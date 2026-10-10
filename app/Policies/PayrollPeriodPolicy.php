<?php

namespace App\Policies;

use App\Models\PayrollPeriod;
use App\Models\User;

class PayrollPeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('payroll.view');
    }

    public function view(
        User $user,
        PayrollPeriod $payrollPeriod
    ): bool {
        return $user->hasPermission('payroll.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('payroll.process');
    }

    public function update(
        User $user,
        PayrollPeriod $payrollPeriod
    ): bool {
        return $user->hasPermission('payroll.process');
    }

    public function delete(
        User $user,
        PayrollPeriod $payrollPeriod
    ): bool {
        return false;
    }

    public function restore(
        User $user,
        PayrollPeriod $payrollPeriod
    ): bool {
        return false;
    }

    public function forceDelete(
        User $user,
        PayrollPeriod $payrollPeriod
    ): bool {
        return false;
    }
}
