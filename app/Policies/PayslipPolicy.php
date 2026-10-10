<?php

namespace App\Policies;

use App\Models\Payslip;
use App\Models\User;

class PayslipPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('payslips.view');
    }

    public function view(
        User $user,
        Payslip $payslip
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->hasPermission('payslips.view')) {
            return false;
        }

        if ($user->hasPermission('employees.view')) {
            return true;
        }

        $viewerEmployeeId = $user->employee()->value('id');

        if ($viewerEmployeeId === null) {
            return false;
        }

        return $payslip->employee_id === $viewerEmployeeId
            && $payslip->published_at !== null;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(
        User $user,
        Payslip $payslip
    ): bool {
        return false;
    }

    public function generate(User $user): bool
    {
        return $user->hasPermission('payslips.generate');
    }

    public function publish(
        User $user,
        Payslip $payslip
    ): bool {
        return $user->hasPermission('payslips.publish');
    }

    public function markViewed(
        User $user,
        Payslip $payslip
    ): bool {
        if (! $user->is_active) {
            return false;
        }

        if (! $user->hasPermission('payslips.view')) {
            return false;
        }

        $viewerEmployeeId = $user->employee()->value('id');

        if ($viewerEmployeeId === null) {
            return false;
        }

        return $payslip->employee_id === $viewerEmployeeId;
    }

    public function delete(
        User $user,
        Payslip $payslip
    ): bool {
        return false;
    }

    public function restore(
        User $user,
        Payslip $payslip
    ): bool {
        return false;
    }

    public function forceDelete(
        User $user,
        Payslip $payslip
    ): bool {
        return false;
    }
}
