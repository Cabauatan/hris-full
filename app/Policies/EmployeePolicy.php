<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('employees.view')
            || $user->hasPermission('employees.view-team');
    }

    public function view(User $user, Employee $employee): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->hasPermission('employees.view')) {
            return true;
        }

        if ($employee->user_id === $user->id) {
            return true;
        }

        if (! $user->hasPermission('employees.view-team')) {
            return false;
        }

        $viewerEmployeeId = $user->employee()->value('id');

        return $viewerEmployeeId !== null
            && $employee->supervisor_employee_id === $viewerEmployeeId;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('employees.create');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->hasPermission('employees.update');
    }

    public function delete(User $user, Employee $employee): bool
    {
        return false;
    }

    public function restore(User $user, Employee $employee): bool
    {
        return false;
    }

    public function forceDelete(User $user, Employee $employee): bool
    {
        return false;
    }
}
