<?php

namespace App\Policies;

use App\Models\SystemSetting;
use App\Models\User;

class SystemSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('settings.view');
    }

    public function view(User $user, SystemSetting $systemSetting): bool
    {
        return $user->hasPermission('settings.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('settings.manage');
    }

    public function update(User $user, SystemSetting $systemSetting): bool
    {
        return $user->hasPermission('settings.manage');
    }

    public function delete(User $user, SystemSetting $systemSetting): bool
    {
        return false;
    }

    public function restore(User $user, SystemSetting $systemSetting): bool
    {
        return false;
    }

    public function forceDelete(User $user, SystemSetting $systemSetting): bool
    {
        return false;
    }
}
