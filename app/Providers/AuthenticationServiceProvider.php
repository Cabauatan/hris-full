<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AuthenticationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('applicant-registration', fn (Request $request): array => [
            Limit::perMinute(5)->by('applicant-register-ip:'.$request->ip()),
        ]);

        RateLimiter::for('applicant-login', function (Request $request): array {
            $email = $request->input('email');
            $account = is_string($email) ? strtolower(trim($email)) : '';

            return [
                Limit::perMinute(5)->by('applicant-login-account:'.hash('sha256', $account).'|'.$request->ip()),
                Limit::perMinute(30)->by('applicant-login-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('password-changes', fn (Request $request): array => [
            Limit::perMinute(5)->by('password-account:'.$request->user()->getAuthIdentifier()),
            Limit::perMinute(30)->by('password-ip:'.$request->ip()),
        ]);

        RateLimiter::for('login', function (Request $request): array {
            $employeeNumber = $request->input('employee_number');
            $account = is_string($employeeNumber) ? strtolower(trim($employeeNumber)) : '';

            return [
                Limit::perMinute(5)->by('login-account:'.hash('sha256', $account).'|'.$request->ip()),
                Limit::perMinute(30)->by('login-ip:'.$request->ip()),
            ];
        });
    }
}
