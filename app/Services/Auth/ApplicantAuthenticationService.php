<?php

namespace App\Services\Auth;

use App\Models\Applicant;
use App\Services\Notification\OutboundNotificationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Throwable;

class ApplicantAuthenticationService
{
    public function __construct(
        private readonly OutboundNotificationService $updates,
        private readonly SingleSessionService $sessions,
    ) {}

    public function register(Request $request, #[SensitiveParameter] array $data): Applicant
    {
        $guard = Auth::guard('applicant');
        abort_if($guard->check(), 409, 'You are already signed in as an applicant.');

        try {
            $applicant = DB::transaction(function () use ($data): Applicant {
                $applicant = Applicant::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'first_name' => $data['first_name'],
                    'middle_name' => $data['middle_name'] ?? null,
                    'last_name' => $data['last_name'],
                    'email' => $data['email'],
                    'mobile_number' => $data['mobile_number'] ?? null,
                    'password' => $data['password'],
                    'is_active' => true,
                ]);
                $this->updates->queue('applicant', $applicant->id, 'registered', $applicant, 'hris/recruitment/applicant/'.$applicant->id.'/registered');

                return $applicant;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! Applicant::query()->where('email', $data['email'])->exists()) {
                throw $exception;
            }

            throw ValidationException::withMessages(['email' => 'The email has already been taken.']);
        }

        $guard->login($applicant);

        return $this->finishLogin($request, $data['password']);
    }

    public function login(Request $request, #[SensitiveParameter] array $credentials): Applicant
    {
        $guard = Auth::guard('applicant');
        abort_if($guard->check(), 409, 'You are already signed in as an applicant.');

        try {
            if (! $guard->attemptWhen([
                'email' => $credentials['email'],
                'password' => $credentials['password'],
                'is_active' => true,
            ], fn (Applicant $applicant): bool => $this->sessions->reserve($request, $applicant, 'applicant', $credentials['password']), (bool) ($credentials['remember'] ?? false))) {
                throw ValidationException::withMessages(['email' => __('auth.failed')]);
            }
        } catch (Throwable $exception) {
            $this->sessions->release($request, 'applicant', $guard->getLastAttempted());
            $this->sessions->discard($request, 'applicant');

            throw $exception;
        }

        return $this->finishLogin($request, $credentials['password']);
    }

    public function logout(Request $request): void
    {
        if (! $this->sessions->release($request, 'applicant')) {
            $this->sessions->discard($request, 'applicant');

            return;
        }

        Auth::guard('applicant')->logout();
        $request->session()->forget('password_hash_applicant');
        $request->session()->regenerate(true);
    }

    private function finishLogin(Request $request, #[SensitiveParameter] string $password): Applicant
    {
        $guard = Auth::guard('applicant');

        try {
            $applicant = DB::transaction(function () use ($request, $guard, $password): Applicant {
                $applicant = Applicant::query()->lockForUpdate()->find($guard->id());

                if ($applicant === null || ! $applicant->is_active || ! Hash::check($password, $applicant->getAuthPassword())) {
                    throw ValidationException::withMessages(['email' => __('auth.failed')]);
                }

                $this->sessions->claim($request, $applicant, 'applicant');
                $applicant->forceFill(['last_login_at' => now()])->save();

                return $applicant;
            });
        } catch (Throwable $exception) {
            $this->sessions->release($request, 'applicant');
            $this->sessions->discard($request, 'applicant');

            throw $exception;
        }

        $guard->setUser($applicant);
        $request->session()->regenerate(true);
        $request->session()->put('password_hash_applicant', $guard->hashPasswordForCookie($applicant->getAuthPassword()));

        return $applicant;
    }
}
