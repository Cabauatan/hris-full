<?php

namespace App\Policies;

use App\Models\PayrollEntryItem;
use App\Models\User;

class PayrollEntryItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('payroll.view');
    }

    public function view(
        User $user,
        PayrollEntryItem $payrollEntryItem
    ): bool {
        return $user->hasPermission('payroll.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(
        User $user,
        PayrollEntryItem $payrollEntryItem
    ): bool {
        return false;
    }

    public function delete(
        User $user,
        PayrollEntryItem $payrollEntryItem
    ): bool {
        return false;
    }

    public function restore(
        User $user,
        PayrollEntryItem $payrollEntryItem
    ): bool {
        return false;
    }

    public function forceDelete(
        User $user,
        PayrollEntryItem $payrollEntryItem
    ): bool {
        return false;
    }
}
