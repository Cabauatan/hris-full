<?php

namespace App\Policies;

use App\Models\EmployeeEmploymentHistory;
use App\Models\User;

class EmployeeEmploymentHistoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('employees.manage-employment');
    }

    public function view(User $user, EmployeeEmploymentHistory $employeeEmploymentHistory): bool
    {
        return $user->hasPermission('employees.manage-employment');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('employees.manage-employment');
    }

    public function update(User $user, EmployeeEmploymentHistory $employeeEmploymentHistory): bool
    {
        return $user->hasPermission('employees.manage-employment');
    }

    public function delete(User $user, EmployeeEmploymentHistory $employeeEmploymentHistory): bool
    {
        return false;
    }

    public function restore(User $user, EmployeeEmploymentHistory $employeeEmploymentHistory): bool
    {
        return false;
    }

    public function forceDelete(User $user, EmployeeEmploymentHistory $employeeEmploymentHistory): bool
    {
        return false;
    }
}
