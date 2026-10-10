<?php

namespace App\Policies;

use App\Models\User;

class WorkspacePolicy
{
    public const CALENDAR_PERMISSIONS = [
        'birthday' => ['employees.view', 'employees.view-team'],
        'holiday' => ['holidays.view'],
        'schedule' => ['schedules.view', 'schedules.assign'],
        'leave' => ['leave.view'],
        'cto' => ['cto.view'],
        'overtime' => ['overtime.view'],
        'official_business' => ['official-business.view'],
        'travel_order' => ['travel-orders.view'],
    ];

    public function dashboard(User $user): bool
    {
        return $user->is_active;
    }

    public function calendar(User $user): bool
    {
        foreach (array_keys(self::CALENDAR_PERMISSIONS) as $type) {
            if ($this->calendarType($user, $type)) {
                return true;
            }
        }

        return false;
    }

    public function calendarType(User $user, string $type): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($type === 'birthday' && $user->employee()->exists()) {
            return true;
        }

        foreach (self::CALENDAR_PERMISSIONS[$type] ?? [] as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }
}
