<?php

namespace App\Policies;

use App\Models\Position;
use App\Models\User;

class PositionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('organization.view');
    }

    public function view(User $user, Position $position): bool
    {
        return $user->hasPermission('organization.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('organization.manage');
    }

    public function update(User $user, Position $position): bool
    {
        return $user->hasPermission('organization.manage');
    }

    public function delete(User $user, Position $position): bool
    {
        return false;
    }

    public function restore(User $user, Position $position): bool
    {
        return false;
    }

    public function forceDelete(User $user, Position $position): bool
    {
        return false;
    }
}
