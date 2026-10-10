<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;

class DepartmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('organization.view');
    }

    public function view(User $user, Department $department): bool
    {
        return $user->hasPermission('organization.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('organization.manage');
    }

    public function update(User $user, Department $department): bool
    {
        return $user->hasPermission('organization.manage');
    }

    public function delete(User $user, Department $department): bool
    {
        return false;
    }

    public function restore(User $user, Department $department): bool
    {
        return false;
    }

    public function forceDelete(User $user, Department $department): bool
    {
        return false;
    }
}
