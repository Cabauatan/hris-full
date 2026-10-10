<?php

namespace App\Policies;

use App\Models\Announcement;
use App\Models\User;

class AnnouncementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('announcements.view')
            || $user->hasPermission('announcements.manage');
    }

    public function view(User $user, Announcement $announcement): bool
    {
        if ($user->hasPermission('announcements.manage')) {
            return true;
        }

        if (! $user->hasPermission('announcements.view')) {
            return false;
        }

        return $announcement->is_active
            && $announcement->published_at !== null
            && $announcement->published_at <= now()
            && (
                $announcement->expires_at === null
                || $announcement->expires_at > now()
            );
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('announcements.manage');
    }

    public function update(User $user, Announcement $announcement): bool
    {
        return $user->hasPermission('announcements.manage');
    }

    public function delete(User $user, Announcement $announcement): bool
    {
        return false;
    }

    public function restore(User $user, Announcement $announcement): bool
    {
        return false;
    }

    public function forceDelete(User $user, Announcement $announcement): bool
    {
        return false;
    }
}
