<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Notification\OutboundNotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Throwable;

class AuthenticationService
{
    public function __construct(
        private readonly OutboundNotificationService $updates,
        private readonly SingleSessionService $sessions,
    ) {}

    public function login(Request $request, #[SensitiveParameter] array $credentials): User
    {
        $guard = Auth::guard('web');
        abort_if($guard->check(), 409, 'You are already authenticated.');

        try {
            if (! $guard->attemptWhen([
                'password' => $credentials['password'],
                'is_active' => true,
                fn (Builder $query): Builder => $query->whereHas('employee', fn (Builder $employees): Builder => $employees
                    ->where('employee_number', $credentials['employee_number'])
                    ->where('is_active', true)),
            ], fn (User $user): bool => $this->sessions->reserve($request, $user, 'web', $credentials['password']), (bool) ($credentials['remember'] ?? false))) {
                throw ValidationException::withMessages(['employee_number' => __('auth.failed')]);
            }

            $user = DB::transaction(function () use ($request, $guard, $credentials): User {
                $user = User::query()->lockForUpdate()->findOrFail($guard->id());
                $employee = $user->employee()->where('employee_number', $credentials['employee_number'])
                    ->where('is_active', true)->lockForUpdate()->first();

                if (! $user->is_active || $employee === null || ! Hash::check($credentials['password'], $user->getAuthPassword())) {
                    throw ValidationException::withMessages(['employee_number' => __('auth.failed')]);
                }

                $this->sessions->claim($request, $user, 'web');
                $user->forceFill(['last_login_at' => now()])->save();

                return $user;
            });
        } catch (Throwable $exception) {
            $this->sessions->release($request, 'web', $guard->getLastAttempted());
            $this->sessions->discard($request, 'web');

            throw $exception;
        }

        $this->refreshSession($request, $user);

        return $this->context($user);
    }

    public function logout(Request $request): void
    {
        if (! $this->sessions->release($request, 'web')) {
            $this->sessions->discard($request, 'web');

            return;
        }

        if ($this->sessions->release($request, 'applicant')) {
            Auth::guard('applicant')->logout();
        } else {
            $this->sessions->discard($request, 'applicant');
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    public function changePassword(Request $request, #[SensitiveParameter] string $currentPassword, #[SensitiveParameter] string $password): User
    {
        $user = DB::transaction(function () use ($request, $currentPassword, $password): User {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);

            if (! $user->is_active || ! Hash::check($currentPassword, $user->getAuthPassword())) {
                throw ValidationException::withMessages(['current_password' => __('auth.password')]);
            }

            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();
            $this->updates->queue('user', $user->id, 'password_changed', $user);

            return $user;
        });

        $this->refreshSession($request, $user);

        return $this->context($user);
    }

    public function context(User $user): User
    {
        return $user->load([
            'roles' => fn (BelongsToMany $roles): BelongsToMany => $roles->where('roles.is_active', true),
            'roles.permissions' => fn (BelongsToMany $permissions): BelongsToMany => $permissions->where('permissions.is_active', true),
            'permissions' => fn (BelongsToMany $permissions): BelongsToMany => $permissions->where('permissions.is_active', true),
            'employee',
        ]);
    }

    private function refreshSession(Request $request, User $user): void
    {
        $guard = Auth::guard('web');
        $guard->setUser($user);
        $request->session()->regenerate(true);
        $request->session()->put('password_hash_web', $guard->hashPasswordForCookie($user->getAuthPassword()));
    }
}
