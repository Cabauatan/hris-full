<?php

namespace App\Policies;

use App\Models\EmployeeCompensation;
use App\Models\User;

class EmployeeCompensationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('employees.manage-compensation');
    }

    public function view(User $user, EmployeeCompensation $employeeCompensation): bool
    {
        return $user->hasPermission('employees.manage-compensation');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('employees.manage-compensation');
    }

    public function update(User $user, EmployeeCompensation $employeeCompensation): bool
    {
        return $user->hasPermission('employees.manage-compensation');
    }

    public function delete(User $user, EmployeeCompensation $employeeCompensation): bool
    {
        return false;
    }

    public function restore(User $user, EmployeeCompensation $employeeCompensation): bool
    {
        return false;
    }

    public function forceDelete(User $user, EmployeeCompensation $employeeCompensation): bool
    {
        return false;
    }
}
