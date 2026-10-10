<?php

namespace App\Policies;

use App\Models\PayrollRun;
use App\Models\User;

class PayrollRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('payroll.view');
    }

    public function view(
        User $user,
        PayrollRun $payrollRun
    ): bool {
        return $user->hasPermission('payroll.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('payroll.process');
    }

    public function update(
        User $user,
        PayrollRun $payrollRun
    ): bool {
        return false;
    }

    public function process(
        User $user,
        PayrollRun $payrollRun
    ): bool {
        return $user->hasPermission('payroll.process');
    }

    public function finalize(
        User $user,
        PayrollRun $payrollRun
    ): bool {
        return $user->hasPermission('payroll.finalize');
    }

    public function delete(
        User $user,
        PayrollRun $payrollRun
    ): bool {
        return false;
    }

    public function restore(
        User $user,
        PayrollRun $payrollRun
    ): bool {
        return false;
    }

    public function forceDelete(
        User $user,
        PayrollRun $payrollRun
    ): bool {
        return false;
    }
}
