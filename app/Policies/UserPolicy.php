<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function view(User $actor, User $user): bool
    {
        return $actor->is_active && $actor->id === $user->id;
    }

    public function updatePassword(User $actor, User $user): bool
    {
        return $actor->is_active && $actor->id === $user->id;
    }
}
