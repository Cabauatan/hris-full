<?php

namespace App\Policies;

use App\Models\EmployeeDocument;
use App\Models\User;

class EmployeeDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('employees.manage-documents');
    }

    public function view(User $user, EmployeeDocument $employeeDocument): bool
    {
        return $user->hasPermission('employees.manage-documents');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('employees.manage-documents');
    }

    public function update(User $user, EmployeeDocument $employeeDocument): bool
    {
        return $user->hasPermission('employees.manage-documents');
    }

    public function delete(User $user, EmployeeDocument $employeeDocument): bool
    {
        return false;
    }

    public function restore(User $user, EmployeeDocument $employeeDocument): bool
    {
        return false;
    }

    public function forceDelete(User $user, EmployeeDocument $employeeDocument): bool
    {
        return false;
    }
}
