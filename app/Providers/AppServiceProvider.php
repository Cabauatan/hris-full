<?php

namespace App\Providers;

use App\Models\Applicant;
use App\Models\User;
use App\Policies\NotificationPolicy;
use App\Policies\ReportPolicy;
use App\Policies\WorkspacePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Broadcast::channel('App.Models.User.{userId}', fn (User $user, string $userId): bool => $user->is_active && (string) $user->getAuthIdentifier() === $userId);
        Broadcast::channel('App.Models.Applicant.{applicantId}', fn (Applicant $applicant, string $applicantId): bool => $applicant->is_active && (string) $applicant->getAuthIdentifier() === $applicantId, ['guards' => ['applicant']]);
        RateLimiter::for('broadcast-auth', fn (Request $request): Limit => Limit::perMinute(60)->by('broadcast-auth:'.$request->user()->getAuthIdentifier()));
        RateLimiter::for('applicant-broadcast-auth', fn (Request $request): Limit => Limit::perMinute(60)->by('applicant-broadcast-auth:'.$request->user()->getAuthIdentifier()));

        Gate::policy(DatabaseNotification::class, NotificationPolicy::class);
        Gate::define('viewDashboard', [WorkspacePolicy::class, 'dashboard']);
        Gate::define('viewCalendar', [WorkspacePolicy::class, 'calendar']);
        Gate::define('viewCalendarType', [WorkspacePolicy::class, 'calendarType']);
        Gate::define('viewReports', [ReportPolicy::class, 'index']);
        Gate::define('viewReport', [ReportPolicy::class, 'view']);
        Gate::define('exportReport', [ReportPolicy::class, 'export']);
        RateLimiter::for('report-exports', fn (Request $request): Limit => Limit::perMinute(5)->by('report-export:'.$request->user()->getAuthIdentifier()));
        RateLimiter::for('telegram-links', fn (Request $request): Limit => Limit::perMinute(5)->by(
            'telegram-link:'.($request->user() instanceof Applicant ? 'applicant' : 'user').':'.$request->user()->getAuthIdentifier().':'.$request->ip()
        ));

        Relation::enforceMorphMap(
            config('morph_map', [])
        );
    }
}
