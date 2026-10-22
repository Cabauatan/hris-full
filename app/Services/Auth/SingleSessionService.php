<?php

namespace App\Services\Auth;

use App\Events\AccountSessionChanged;
use App\Models\Applicant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use SensitiveParameter;

class SingleSessionService
{
    /** The caller must wrap reservation and login finalization in the same transaction. */
    public function reserve(User|Applicant $account, #[SensitiveParameter] string $password): bool
    {
        return DB::transaction(function () use ($account, $password): bool {
            $locked = $account->newQuery()->lockForUpdate()->find($account->getKey());

            if ($locked === null || ! $locked->is_active || ! Hash::check($password, $locked->getAuthPassword())) {
                return false;
            }

            if (is_string($locked->active_session_token)) {
                $locked->forceFill(['remember_token' => Str::random(60)]);
                $locked::withoutTimestamps(fn (): bool => $locked->saveQuietly());
                $account->setRememberToken($locked->getRememberToken());
            }

            return true;
        }, 3);
    }

    /** The caller must hold the account row lock inside a transaction. */
    public function claim(Request $request, User|Applicant $account, string $guard): void
    {
        $replacesSession = is_string($account->active_session_token) && ! $this->owns($request, $account, $guard);

        $this->assign($request, $account, $guard);

        if ($replacesSession && config('broadcasting.default') === 'reverb') {
            $accountId = (int) $account->getKey();
            DB::afterCommit(static fn () => rescue(static fn () => AccountSessionChanged::dispatch($guard, $accountId)));
        }
    }

    public function validate(Request $request, User|Applicant $account, string $guard): bool
    {
        return DB::transaction(function () use ($request, $account, $guard): bool {
            $locked = $account->newQuery()->lockForUpdate()->find($account->getKey());

            if ($locked === null) {
                return false;
            }

            if ($this->owns($request, $locked, $guard)) {
                $this->touch($locked);

                return true;
            }

            if ($request->session()->has($this->sessionKey($guard)) || $this->isActive($locked)) {
                return false;
            }

            $this->assign($request, $locked, $guard);

            return true;
        }, 3);
    }

    public function release(Request $request, string $guard, User|Applicant|null $account = null): bool
    {
        $account ??= Auth::guard($guard)->user();

        if (! ($account instanceof User || $account instanceof Applicant)) {
            return false;
        }

        $released = DB::transaction(function () use ($request, $account, $guard): bool {
            $locked = $account->newQuery()->lockForUpdate()->find($account->getKey());

            if ($locked === null || ! $this->owns($request, $locked, $guard)) {
                return false;
            }

            $locked->forceFill([
                'active_session_token' => null,
                'active_session_last_activity_at' => null,
            ]);
            $locked::withoutTimestamps(fn (): bool => $locked->saveQuietly());

            return true;
        }, 3);

        $request->session()->forget($this->sessionKey($guard));

        return $released;
    }

    public function discard(Request $request, string $guard): void
    {
        Auth::guard($guard)->logoutCurrentDevice();
        $request->session()->forget([$this->sessionKey($guard), 'password_hash_'.$guard]);
        $request->session()->regenerate(true);
    }

    private function assign(Request $request, User|Applicant $account, string $guard): void
    {
        if (! $this->owns($request, $account, $guard)) {
            $token = Str::random(64);
            $request->session()->put($this->sessionKey($guard), $token);
            $account->forceFill(['active_session_token' => hash('sha256', $token)]);
        }

        $this->touch($account);
    }

    private function touch(User|Applicant $account): void
    {
        $account->forceFill(['active_session_last_activity_at' => now()]);
        $account::withoutTimestamps(fn (): bool => $account->saveQuietly());
    }

    private function isActive(User|Applicant $account): bool
    {
        return is_string($account->active_session_token)
            && $account->active_session_last_activity_at?->gt(now()->subMinutes(max(1, (int) config('session.lifetime'))));
    }

    private function owns(Request $request, User|Applicant $account, string $guard): bool
    {
        $token = $request->session()->get($this->sessionKey($guard));

        return is_string($token) && is_string($account->active_session_token)
            && hash_equals($account->active_session_token, hash('sha256', $token));
    }

    private function sessionKey(string $guard): string
    {
        return 'single_session_'.$guard;
    }
}
