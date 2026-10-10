<?php

namespace App\Policies;

use App\Models\PayrollEntry;
use App\Models\User;

class PayrollEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('payroll.view');
    }

    public function view(
        User $user,
        PayrollEntry $payrollEntry
    ): bool {
        return $user->hasPermission('payroll.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(
        User $user,
        PayrollEntry $payrollEntry
    ): bool {
        return false;
    }

    public function process(User $user): bool
    {
        return $user->hasPermission('payroll.process');
    }

    public function delete(
        User $user,
        PayrollEntry $payrollEntry
    ): bool {
        return false;
    }

    public function restore(
        User $user,
        PayrollEntry $payrollEntry
    ): bool {
        return false;
    }

    public function forceDelete(
        User $user,
        PayrollEntry $payrollEntry
    ): bool {
        return false;
    }
}
