<?php

namespace App\Services\Approval;

use App\Models\ApprovalDelegation;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

class ApprovalAuthorityResolver
{
    public const TYPE_ATTENDANCE_CORRECTION = 'attendance_correction';

    public const TYPE_OFFICIAL_BUSINESS = 'official_business';

    public const TYPE_TRAVEL_ORDER = 'travel_order';

    public const TYPE_OVERTIME = 'overtime';

    public const TYPE_LEAVE = 'leave';

    public const TYPE_CTO = 'cto';

    public const SUPPORTED_TYPES = [
        self::TYPE_ATTENDANCE_CORRECTION,
        self::TYPE_OFFICIAL_BUSINESS,
        self::TYPE_TRAVEL_ORDER,
        self::TYPE_OVERTIME,
        self::TYPE_LEAVE,
        self::TYPE_CTO,
    ];

    public function resolve(Employee $employee, string $requestType): ?User
    {
        $this->ensureSupportedType($requestType);

        $supervisor = $employee->supervisor;

        if (! $supervisor) {
            return null;
        }

        $user = $supervisor->user;

        if (! $user) {
            return null;
        }

        $requiredPermission = $this->requiredPermission($requestType);

        if (! $user->hasPermission($requiredPermission)) {
            return null;
        }

        $delegate = $this->resolveDelegate($user, $requestType);

        return $delegate ?? $user;
    }

    public function isSupportedType(string $requestType): bool
    {
        return in_array(
            $requestType,
            self::SUPPORTED_TYPES,
            true
        );
    }

    public function requiredPermission(string $requestType): string
    {
        $this->ensureSupportedType($requestType);

        return match ($requestType) {
            self::TYPE_ATTENDANCE_CORRECTION => 'attendance-corrections.approve',
            self::TYPE_OFFICIAL_BUSINESS => 'official-business.approve',
            self::TYPE_TRAVEL_ORDER => 'travel-orders.approve',
            self::TYPE_OVERTIME => 'overtime.approve',
            self::TYPE_LEAVE => 'leave.approve',
            self::TYPE_CTO => 'cto.approve',
        };
    }

    public function resolveDelegate(User $approver, string $requestType): ?User
    {
        $requiredPermission = $this->requiredPermission($requestType);

        $delegation = ApprovalDelegation::query()
            ->where('delegator_user_id', $approver->id)
            ->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->latest('starts_at')
            ->latest('id')
            ->first();

        if (! $delegation) {
            return null;
        }

        $delegate = $delegation->delegate;

        if (! $delegate) {
            return null;
        }
        if ($delegate->id === $approver->id) {
            return null;
        }

        if (! $delegate->hasPermission($requiredPermission)) {
            return null;
        }

        return $delegate;
    }

    public function delegatedEmployees(User $delegate, string $requestType): Builder
    {
        $requiredPermission = $this->requiredPermission($requestType);

        if (! $delegate->hasPermission($requiredPermission)) {
            return Employee::query()->whereRaw('1 = 0');
        }

        $now = now();
        $latestDelegate = ApprovalDelegation::query()
            ->select('delegate_user_id')
            ->whereColumn('delegator_user_id', 'users.id')
            ->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->latest('starts_at')
            ->latest('id')
            ->limit(1);

        $permission = fn (Builder $permissions) => $permissions
            ->where('permissions.code', $requiredPermission)
            ->where('permissions.is_active', true);

        return Employee::query()->whereHas('supervisor.user', function (Builder $users) use ($delegate, $permission, $latestDelegate): void {
            $users->where('users.is_active', true)
                ->where('users.id', '!=', $delegate->id)
                ->where(function (Builder $users) use ($permission): void {
                    $users->whereHas('permissions', $permission)
                        ->orWhereHas('roles', fn (Builder $roles) => $roles
                            ->where('roles.is_active', true)
                            ->whereHas('permissions', $permission));
                })
                ->where($latestDelegate, $delegate->id);
        });
    }

    private function ensureSupportedType(string $requestType): void
    {
        if (! $this->isSupportedType($requestType)) {
            throw new InvalidArgumentException(
                "Unsupported approval request type: {$requestType}"
            );
        }
    }
}
