<?php

namespace Tests\Feature;

use App\Events\AccountSessionChanged;
use App\Models\Applicant;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\FormRequestTestCase;

class SingleSessionAuthenticationTest extends FormRequestTestCase
{
    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_second_browser_login_takes_over_and_returns_401_for_first_browser(string $guard): void
    {
        [$account, $credentials, $prefix] = $this->account($guard);
        $this->freezeTime();
        $first = $this->postJson($prefix.'/login', $credentials + ['remember' => true])->assertOk();
        $firstCookies = $this->cookiesFrom($first);
        $originalLease = $account->fresh()->active_session_token;
        $originalRememberToken = $account->fresh()->remember_token;
        $originalLogin = $account->fresh()->last_login_at;
        $this->travel(1)->minutes();
        $this->useBrowserCookies([]);

        $second = $this->postJson($prefix.'/login', $credentials + ['remember' => true])->assertOk();

        $this->assertAuthenticatedAs($account, $guard);
        $this->assertNotEmpty($originalLease);
        $winningLease = $account->fresh()->active_session_token;
        $this->assertNotSame($originalLease, $winningLease);
        $this->assertNotSame($originalRememberToken, $account->fresh()->remember_token);
        $this->assertTrue($account->fresh()->last_login_at->gt($originalLogin));
        $this->useBrowserCookies($firstCookies);
        $this->getJson($prefix.'/me')->assertUnauthorized();
        $this->assertSame($winningLease, $account->fresh()->active_session_token);
        $this->useBrowserCookies($this->cookiesFrom($second));
        $this->getJson($prefix.'/me')->assertOk()->assertJsonPath('data.id', $account->id);
    }

    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_wrong_password_returns_generic_422_when_account_has_active_session(string $guard): void
    {
        [$account, $credentials, $prefix] = $this->account($guard);
        $first = $this->postJson($prefix.'/login', $credentials + ['remember' => true])->assertOk();
        $lease = $account->fresh()->active_session_token;
        $remember = $account->fresh()->remember_token;
        $lastLogin = $account->fresh()->last_login_at;
        $this->useBrowserCookies([]);
        $field = $guard === 'web' ? 'employee_number' : 'email';

        $this->postJson($prefix.'/login', array_replace($credentials, ['password' => 'wrong-password']))
            ->assertUnprocessable()->assertJsonValidationErrors($field)
            ->assertJsonPath('errors.'.$field.'.0', __('auth.failed'));

        $this->assertSame($lease, $account->fresh()->active_session_token);
        $this->assertSame($remember, $account->fresh()->remember_token);
        $this->assertTrue($lastLogin->equalTo($account->fresh()->last_login_at));
        $this->useBrowserCookies($this->cookiesFrom($first));
        $this->getJson($prefix.'/me')->assertOk();
    }

    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_takeover_rehashes_password_without_old_session_releasing_new_owner(string $guard): void
    {
        config(['hashing.bcrypt.rounds' => 4]);
        Hash::forgetDrivers();
        [$account, $credentials, $prefix] = $this->account($guard);
        $first = $this->postJson($prefix.'/login', $credentials + ['remember' => true])->assertOk();
        $originalHash = $account->fresh()->getAuthPassword();
        config(['hashing.bcrypt.rounds' => 5]);
        Hash::forgetDrivers();
        $this->assertTrue(Hash::needsRehash($originalHash));
        $this->useBrowserCookies([]);

        $second = $this->postJson($prefix.'/login', $credentials + ['remember' => true])->assertOk();

        $winningLease = $account->fresh()->active_session_token;
        $winningRemember = $account->fresh()->remember_token;
        $this->assertNotSame($originalHash, $account->fresh()->getAuthPassword());
        $this->assertFalse(Hash::needsRehash($account->fresh()->getAuthPassword()));
        $this->useBrowserCookies($this->cookiesFrom($first));
        $this->getJson($prefix.'/me')->assertUnauthorized();
        $this->assertSame($winningLease, $account->fresh()->active_session_token);
        $this->assertSame($winningRemember, $account->fresh()->remember_token);
        $this->useBrowserCookies($this->cookiesFrom($second));
        $this->getJson($prefix.'/me')->assertOk();
    }

    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_displaced_browser_logout_does_not_release_or_change_new_owner(string $guard): void
    {
        [$account, $credentials, $prefix] = $this->account($guard);
        $first = $this->postJson($prefix.'/login', $credentials + ['remember' => true])->assertOk();
        $this->useBrowserCookies([]);
        $second = $this->postJson($prefix.'/login', $credentials + ['remember' => true])->assertOk();
        $winningLease = $account->fresh()->active_session_token;
        $winningRemember = $account->fresh()->remember_token;
        $this->useBrowserCookies($this->cookiesFrom($first));

        $logout = $this->postJson($prefix.'/logout')->assertNoContent();

        $this->assertSame($winningLease, $account->fresh()->active_session_token);
        $this->assertSame($winningRemember, $account->fresh()->remember_token);
        $this->useBrowserCookies($this->cookiesFrom($logout));
        $this->getJson($prefix.'/me')->assertUnauthorized();
        $this->useBrowserCookies($this->cookiesFrom($second));
        $this->getJson($prefix.'/me')->assertOk()->assertJsonPath('data.id', $account->id);
    }

    #[TestWith(['web', 'logout'])]
    #[TestWith(['applicant', 'logout'])]
    #[TestWith(['web', 'expiration'])]
    #[TestWith(['applicant', 'expiration'])]
    public function test_old_remember_cookie_cannot_recover_after_winning_session_ends(string $guard, string $ending): void
    {
        config(['session.lifetime' => 5]);
        $this->freezeTime();
        [$account, $credentials, $prefix] = $this->account($guard);
        $first = $this->postJson($prefix.'/login', $credentials + ['remember' => true])->assertOk();
        $firstCookies = $this->cookiesFrom($first);
        $recallerName = auth($guard)->getRecallerName();
        $this->useBrowserCookies([]);
        $second = $this->postJson($prefix.'/login', $credentials + ['remember' => true])->assertOk();
        $secondCookies = $this->cookiesFrom($second);
        $winningLease = $account->fresh()->active_session_token;

        if ($ending === 'logout') {
            $this->useBrowserCookies($secondCookies);
            $this->postJson($prefix.'/logout')->assertNoContent();
        } else {
            $this->travel(6)->minutes();
        }
        $this->useBrowserCookies([$recallerName => $firstCookies[$recallerName]]);

        $this->getJson($prefix.'/me')->assertUnauthorized();

        if ($ending === 'logout') {
            $this->assertNull($account->fresh()->active_session_token);
        } else {
            $this->assertSame($winningLease, $account->fresh()->active_session_token);
            $this->useBrowserCookies([$recallerName => $secondCookies[$recallerName]]);
            $this->getJson($prefix.'/me')->assertOk()->assertJsonPath('data.id', $account->id);
            $this->assertNotSame($winningLease, $account->fresh()->active_session_token);
        }
    }

    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_inactive_account_login_returns_422_without_changing_existing_ownership(string $guard): void
    {
        [$account, $credentials, $prefix] = $this->account($guard);
        $first = $this->postJson($prefix.'/login', $credentials + ['remember' => true])->assertOk();
        $original = $account->fresh();
        $account->update(['is_active' => false]);
        $this->useBrowserCookies([]);
        $field = $guard === 'web' ? 'employee_number' : 'email';

        $this->postJson($prefix.'/login', $credentials)->assertUnprocessable()
            ->assertJsonValidationErrors($field)->assertJsonPath('errors.'.$field.'.0', __('auth.failed'));

        $current = $account->fresh();
        $this->assertSame($original->active_session_token, $current->active_session_token);
        $this->assertSame($original->remember_token, $current->remember_token);
        $this->assertTrue($original->last_login_at->equalTo($current->last_login_at));
        $account->update(['is_active' => true]);
        $this->useBrowserCookies($this->cookiesFrom($first));
        $this->getJson($prefix.'/me')->assertOk();
    }

    #[TestWith(['web', 'saving'])]
    #[TestWith(['applicant', 'saving'])]
    #[TestWith(['web', 'login-event'])]
    #[TestWith(['applicant', 'login-event'])]
    public function test_failed_login_finalization_rolls_back_takeover_and_preserves_owner(string $guard, string $failure): void
    {
        $this->freezeTime();
        [$account, $credentials, $prefix] = $this->account($guard);
        $first = $this->postJson($prefix.'/login', $credentials + ['remember' => true])->assertOk();
        $original = $account->fresh();
        $this->travel(1)->minutes();
        $interrupted = false;
        if ($failure === 'saving') {
            $account::saving(function (User|Applicant $saving) use ($account, &$interrupted): void {
                if (! $interrupted && $saving->getKey() === $account->getKey() && $saving->isDirty('last_login_at')) {
                    $interrupted = true;
                    throw new RuntimeException('Login finalization failed.');
                }
            });
        } else {
            Event::listen(Login::class, function (Login $event) use ($account, $guard, &$interrupted): void {
                if (! $interrupted && $event->guard === $guard && $event->user->getAuthIdentifier() === $account->getKey()) {
                    $interrupted = true;
                    throw new RuntimeException('Login finalization failed.');
                }
            });
        }
        config(['broadcasting.default' => 'reverb']);
        Event::fake([AccountSessionChanged::class]);
        $this->useBrowserCookies([]);

        $failed = $this->postJson($prefix.'/login', $credentials + ['remember' => true])->assertInternalServerError();

        $this->assertTrue($interrupted);
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Login finalization failed.');
        Event::assertNotDispatched(AccountSessionChanged::class);
        $current = $account->fresh();
        $this->assertSame($original->active_session_token, $current->active_session_token);
        $this->assertSame($original->remember_token, $current->remember_token);
        $this->assertTrue($original->last_login_at->equalTo($current->last_login_at));
        $this->useBrowserCookies($this->cookiesFrom($failed));
        $this->getJson($prefix.'/me')->assertUnauthorized();
        $this->useBrowserCookies($this->cookiesFrom($first));
        $this->getJson($prefix.'/me')->assertOk()->assertJsonPath('data.id', $account->id);
    }

    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_takeover_broadcasts_only_committed_guard_change_on_private_account_channel(string $guard): void
    {
        [$account, $credentials, $prefix] = $this->account($guard);
        config(['broadcasting.default' => 'reverb']);
        Event::fake([AccountSessionChanged::class]);
        $this->postJson($prefix.'/login', $credentials)->assertOk();
        $this->useBrowserCookies([]);

        $this->postJson($prefix.'/login', $credentials)->assertOk();

        Event::assertDispatched(AccountSessionChanged::class, 1);
        $event = Event::dispatched(AccountSessionChanged::class)->first()[0];
        $this->assertSame($guard, $event->guard);
        $this->assertSame($account->id, $event->accountId);
        $model = $guard === 'web' ? 'User' : 'Applicant';
        $this->assertSame('private-App.Models.'.$model.'.'.$account->id, $event->broadcastOn()[0]->name);
        $this->assertSame('account.session.changed', $event->broadcastAs());
        $this->assertSame('notifications', $event->broadcastQueue());
        $this->assertSame(['guard' => $guard], $event->broadcastWith());
    }

    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_takeover_with_rehash_discards_only_displaced_guard_in_shared_browser(string $guard): void
    {
        config(['hashing.bcrypt.rounds' => 4]);
        Hash::forgetDrivers();
        [$staff, $employeeCredentials, $employeePrefix] = $this->account('web');
        [$candidate, $applicantCredentials, $applicantPrefix] = $this->account('applicant');
        $employeeLogin = $this->postJson($employeePrefix.'/login', $employeeCredentials + ['remember' => true])->assertOk();
        $this->useBrowserCookies($this->cookiesFrom($employeeLogin));
        $applicantLogin = $this->postJson($applicantPrefix.'/login', $applicantCredentials + ['remember' => true])->assertOk();
        $sharedCookies = array_replace($this->cookiesFrom($employeeLogin), $this->cookiesFrom($applicantLogin));
        [$account, $credentials, $prefix] = $guard === 'web'
            ? [$staff, $employeeCredentials, $employeePrefix]
            : [$candidate, $applicantCredentials, $applicantPrefix];
        [$unrelated, $otherPrefix] = $guard === 'web'
            ? [$candidate, $applicantPrefix]
            : [$staff, $employeePrefix];
        $otherLease = $unrelated->fresh()->active_session_token;
        config(['hashing.bcrypt.rounds' => 5]);
        Hash::forgetDrivers();
        $this->useBrowserCookies([]);
        $winner = $this->postJson($prefix.'/login', $credentials + ['remember' => true])->assertOk();
        $winningLease = $account->fresh()->active_session_token;
        $this->useBrowserCookies($sharedCookies);

        $displaced = $this->getJson($prefix.'/me')->assertUnauthorized();

        $this->assertSame($winningLease, $account->fresh()->active_session_token);
        $this->assertSame($otherLease, $unrelated->fresh()->active_session_token);
        $this->useBrowserCookies(array_replace($sharedCookies, $this->cookiesFrom($displaced)));
        $this->getJson($otherPrefix.'/me')->assertOk()->assertJsonPath('data.id', $unrelated->id);
        $this->useBrowserCookies($this->cookiesFrom($winner));
        $this->getJson($prefix.'/me')->assertOk()->assertJsonPath('data.id', $account->id);
    }

    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_logout_frees_the_account_for_another_browser(string $guard): void
    {
        [$account, $credentials, $prefix] = $this->account($guard);
        $first = $this->postJson($prefix.'/login', $credentials)->assertOk();
        $firstLease = $account->fresh()->active_session_token;
        $this->useBrowserCookies($this->cookiesFrom($first));

        $this->postJson($prefix.'/logout')->assertNoContent();

        $this->assertNull($account->fresh()->active_session_token);
        $this->assertNull($account->fresh()->active_session_last_activity_at);
        $this->useBrowserCookies([]);
        $second = $this->postJson($prefix.'/login', $credentials)->assertOk();
        $this->assertNotSame($firstLease, $account->fresh()->active_session_token);
        $this->useBrowserCookies($this->cookiesFrom($second));
        $this->getJson($prefix.'/me')->assertOk();
        $this->useBrowserCookies($this->cookiesFrom($first));
        $this->getJson($prefix.'/me')->assertUnauthorized();
    }

    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_idle_expiration_allows_new_login_and_returns_401_for_old_browser(string $guard): void
    {
        config(['session.lifetime' => 5]);
        $this->freezeTime();
        [$account, $credentials, $prefix] = $this->account($guard);
        $first = $this->postJson($prefix.'/login', $credentials + ['remember' => true])->assertOk();
        $firstCookies = $this->cookiesFrom($first);
        $firstLease = $account->fresh()->active_session_token;
        $this->travel(6)->minutes();
        $this->useBrowserCookies([]);

        $second = $this->postJson($prefix.'/login', $credentials + ['remember' => true])->assertOk();

        $secondCookies = $this->cookiesFrom($second);
        $secondLease = $account->fresh()->active_session_token;
        $this->assertNotSame($firstLease, $secondLease);
        $this->useBrowserCookies($firstCookies);
        $this->getJson($prefix.'/me')->assertUnauthorized();
        $this->assertSame($secondLease, $account->fresh()->active_session_token);
        $this->useBrowserCookies($secondCookies);
        $this->getJson($prefix.'/me')->assertOk()->assertJsonPath('data.id', $account->id);
    }

    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_authenticated_requests_renew_the_idle_window(string $guard): void
    {
        config(['session.lifetime' => 5]);
        $this->freezeTime();
        [$account, $credentials, $prefix] = $this->account($guard);
        $first = $this->postJson($prefix.'/login', $credentials)->assertOk();
        $firstLease = $account->fresh()->active_session_token;
        $this->travel(4)->minutes();
        $this->useBrowserCookies($this->cookiesFrom($first));
        $renewed = $this->getJson($prefix.'/me')->assertOk();
        $this->assertSame(now()->format('Y-m-d H:i:s'), $account->fresh()->active_session_last_activity_at->format('Y-m-d H:i:s'));
        $this->travel(2)->minutes();
        $this->useBrowserCookies($this->cookiesFrom($renewed));

        $this->getJson($prefix.'/me')->assertOk();

        $this->assertSame($firstLease, $account->fresh()->active_session_token);
        $this->assertSame(now()->format('Y-m-d H:i:s'), $account->fresh()->active_session_last_activity_at->format('Y-m-d H:i:s'));
    }

    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_remember_cookie_returns_401_without_stealing_or_releasing_active_session(string $guard): void
    {
        [$account, $credentials, $prefix] = $this->account($guard);
        $first = $this->postJson($prefix.'/login', $credentials + ['remember' => true])->assertOk();
        $firstCookies = $this->cookiesFrom($first);
        $recallerName = auth($guard)->getRecallerName();
        $lease = $account->fresh()->active_session_token;
        $remember = $account->fresh()->remember_token;
        $this->assertArrayHasKey($recallerName, $firstCookies);
        $this->useBrowserCookies([$recallerName => $firstCookies[$recallerName]]);

        $this->getJson($prefix.'/me')->assertUnauthorized();

        $this->assertSame($lease, $account->fresh()->active_session_token);
        $this->assertSame($remember, $account->fresh()->remember_token);
        $this->useBrowserCookies([$recallerName => $firstCookies[$recallerName]]);
        $this->postJson($prefix.'/logout')->assertNoContent();
        $this->assertSame($lease, $account->fresh()->active_session_token);
        $this->assertSame($remember, $account->fresh()->remember_token);
        $this->useBrowserCookies($firstCookies);
        $this->getJson($prefix.'/me')->assertOk();
    }

    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_same_browser_tabs_remain_usable_and_identity_switch_returns_409(string $guard): void
    {
        [$account, $credentials, $prefix] = $this->account($guard);
        [, $otherCredentials] = $this->account($guard);
        $first = $this->postJson($prefix.'/login', $credentials)->assertOk();
        $cookies = $this->cookiesFrom($first);
        $lease = $account->fresh()->active_session_token;

        foreach (range(1, 3) as $tab) {
            $this->useBrowserCookies($cookies);
            $this->getJson($prefix.'/me')->assertOk()->assertJsonPath('data.id', $account->id);
        }

        $this->useBrowserCookies($cookies);
        $this->postJson($prefix.'/login', $otherCredentials)->assertConflict();
        $this->assertSame($lease, $account->fresh()->active_session_token);
        $this->useBrowserCookies($cookies);
        $this->getJson($prefix.'/me')->assertOk()->assertJsonPath('data.id', $account->id);
    }

    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_lease_fields_are_hidden_in_identity_response_and_model_serialization(string $guard): void
    {
        [$account, $credentials, $prefix] = $this->account($guard);
        $response = $this->postJson($prefix.'/login', $credentials)->assertOk();

        $response->assertJsonMissingPath('data.active_session_token')
            ->assertJsonMissingPath('data.active_session_last_activity_at');
        $serialized = $account->fresh()->toArray();
        $this->assertArrayNotHasKey('active_session_token', $serialized);
        $this->assertArrayNotHasKey('active_session_last_activity_at', $serialized);
    }

    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_remember_cookie_can_recover_login_after_idle_expiration_with_no_new_owner(string $guard): void
    {
        config(['session.lifetime' => 5]);
        $this->freezeTime();
        [$account, $credentials, $prefix] = $this->account($guard);
        $first = $this->postJson($prefix.'/login', $credentials + ['remember' => true])->assertOk();
        $cookies = $this->cookiesFrom($first);
        $recallerName = auth($guard)->getRecallerName();
        $oldLease = $account->fresh()->active_session_token;
        $this->travel(6)->minutes();
        $this->useBrowserCookies([$recallerName => $cookies[$recallerName]]);

        $restored = $this->getJson($prefix.'/me')->assertOk()->assertJsonPath('data.id', $account->id);

        $this->assertNotSame($oldLease, $account->fresh()->active_session_token);
        $this->useBrowserCookies($this->cookiesFrom($restored));
        $this->getJson($prefix.'/me')->assertOk();
        $this->useBrowserCookies([]);
        $next = $this->postJson($prefix.'/login', $credentials)->assertOk();
        $this->useBrowserCookies($this->cookiesFrom($restored));
        $this->getJson($prefix.'/me')->assertUnauthorized();
        $this->useBrowserCookies($this->cookiesFrom($next));
        $this->getJson($prefix.'/me')->assertOk();
    }

    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_first_historical_browser_claims_ownership_and_other_historical_browser_returns_401(string $guard): void
    {
        [$account, , $prefix] = $this->account($guard);
        $this->app['router']->middleware('web')->post('/testing/legacy-session', function () use ($account, $guard) {
            auth($guard)->login($account);
            request()->session()->put('password_hash_'.$guard, auth($guard)->hashPasswordForCookie($account->getAuthPassword()));

            return response()->noContent();
        });
        $first = $this->postJson('/testing/legacy-session')->assertNoContent();
        $firstCookies = $this->cookiesFrom($first);
        $this->useBrowserCookies([]);
        $second = $this->postJson('/testing/legacy-session')->assertNoContent();
        $this->useBrowserCookies($firstCookies);

        $claimed = $this->getJson($prefix.'/me')->assertOk();

        $lease = $account->fresh()->active_session_token;
        $this->assertNotEmpty($lease);
        $this->useBrowserCookies($this->cookiesFrom($second));
        $this->getJson($prefix.'/me')->assertUnauthorized();
        $this->assertSame($lease, $account->fresh()->active_session_token);
        $this->useBrowserCookies($this->cookiesFrom($claimed));
        $this->getJson($prefix.'/me')->assertOk();
    }

    public function test_applicant_login_takes_over_the_registration_session(): void
    {
        $registration = $this->postJson('/api/v1/applicant/auth/register', [
            'first_name' => 'External', 'last_name' => 'Candidate', 'email' => 'registered@example.test',
            'password' => 'applicant-password', 'password_confirmation' => 'applicant-password',
        ])->assertCreated();
        $account = Applicant::query()->findOrFail($registration->json('data.id'));
        $this->assertNotEmpty($account->active_session_token);
        $this->useBrowserCookies([]);

        $login = $this->postJson('/api/v1/applicant/auth/login', ['email' => $account->email, 'password' => 'applicant-password'])->assertOk();

        $this->assertNotSame($account->active_session_token, $account->fresh()->active_session_token);
        $this->useBrowserCookies($this->cookiesFrom($registration));
        $this->getJson('/api/v1/applicant/auth/me')->assertUnauthorized();
        $this->useBrowserCookies($this->cookiesFrom($login));
        $this->getJson('/api/v1/applicant/auth/me')->assertOk();
    }

    public function test_applicant_logout_and_session_rotation_preserve_employee_ownership(): void
    {
        [$staff, $employeeCredentials, $employeePrefix] = $this->account('web');
        [$applicant, $applicantCredentials, $applicantPrefix] = $this->account('applicant');
        $employeeLogin = $this->postJson($employeePrefix.'/login', $employeeCredentials)->assertOk();
        $employeeLease = $staff->fresh()->active_session_token;
        $this->useBrowserCookies($this->cookiesFrom($employeeLogin));
        $applicantLogin = $this->postJson($applicantPrefix.'/login', $applicantCredentials)->assertOk();
        $bothCookies = $this->cookiesFrom($applicantLogin);
        $this->useBrowserCookies($bothCookies);
        $this->getJson($employeePrefix.'/me')->assertOk();
        $this->useBrowserCookies($bothCookies);

        $logout = $this->postJson($applicantPrefix.'/logout')->assertNoContent();

        $this->assertNull($applicant->fresh()->active_session_token);
        $this->assertSame($employeeLease, $staff->fresh()->active_session_token);
        $this->useBrowserCookies($this->cookiesFrom($logout));
        $this->getJson($employeePrefix.'/me')->assertOk()->assertJsonPath('data.id', $staff->id);
        $this->useBrowserCookies($this->cookiesFrom($logout));
        $this->getJson($applicantPrefix.'/me')->assertUnauthorized();
        $this->useBrowserCookies([]);
        $this->postJson($applicantPrefix.'/login', $applicantCredentials)->assertOk();
    }

    public function test_applicant_takeover_preserves_employee_session_in_same_browser(): void
    {
        [$candidate, $applicantCredentials, $applicantPrefix] = $this->account('applicant');
        $applicantLogin = $this->postJson($applicantPrefix.'/login', $applicantCredentials)->assertOk();
        $applicantLease = $candidate->fresh()->active_session_token;
        $this->useBrowserCookies([]);
        [$staff, $employeeCredentials, $employeePrefix] = $this->account('web');
        $employeeLogin = $this->postJson($employeePrefix.'/login', $employeeCredentials)->assertOk();
        $employeeLease = $staff->fresh()->active_session_token;
        $this->useBrowserCookies($this->cookiesFrom($employeeLogin));

        $takeover = $this->postJson($applicantPrefix.'/login', $applicantCredentials)->assertOk();

        $this->assertSame($employeeLease, $staff->fresh()->active_session_token);
        $this->assertNotSame($applicantLease, $candidate->fresh()->active_session_token);
        $winningLease = $candidate->fresh()->active_session_token;
        $this->useBrowserCookies($this->cookiesFrom($takeover));
        $this->getJson($employeePrefix.'/me')->assertOk();
        $this->useBrowserCookies($this->cookiesFrom($takeover));
        $this->getJson($applicantPrefix.'/me')->assertOk();
        $this->useBrowserCookies($this->cookiesFrom($applicantLogin));
        $this->getJson($applicantPrefix.'/me')->assertUnauthorized();
        $this->assertSame($winningLease, $candidate->fresh()->active_session_token);
    }

    public function test_employee_logout_releases_both_guard_leases_when_shared_browser_session_is_invalidated(): void
    {
        [$staff, $employeeCredentials, $employeePrefix] = $this->account('web');
        [$candidate, $applicantCredentials, $applicantPrefix] = $this->account('applicant');
        $employeeLogin = $this->postJson($employeePrefix.'/login', $employeeCredentials)->assertOk();
        $this->useBrowserCookies($this->cookiesFrom($employeeLogin));
        $applicantLogin = $this->postJson($applicantPrefix.'/login', $applicantCredentials)->assertOk();
        $this->useBrowserCookies($this->cookiesFrom($applicantLogin));

        $logout = $this->postJson($employeePrefix.'/logout')->assertNoContent();

        $this->assertNull($staff->fresh()->active_session_token);
        $this->assertNull($candidate->fresh()->active_session_token);
        $this->useBrowserCookies($this->cookiesFrom($logout));
        $this->getJson($employeePrefix.'/me')->assertUnauthorized();
        $this->useBrowserCookies($this->cookiesFrom($logout));
        $this->getJson($applicantPrefix.'/me')->assertUnauthorized();
        $this->useBrowserCookies([]);
        $this->postJson($employeePrefix.'/login', $employeeCredentials)->assertOk();
        $this->useBrowserCookies([]);
        $this->postJson($applicantPrefix.'/login', $applicantCredentials)->assertOk();
    }

    public function test_employee_logout_expires_unowned_applicant_remember_cookie_without_displacing_applicant_owner(): void
    {
        [$candidate, $applicantCredentials, $applicantPrefix] = $this->account('applicant');
        $applicantLogin = $this->postJson($applicantPrefix.'/login', $applicantCredentials + ['remember' => true])->assertOk();
        $applicantCookies = $this->cookiesFrom($applicantLogin);
        $recallerName = auth('applicant')->getRecallerName();
        $applicantLease = $candidate->fresh()->active_session_token;
        $rememberToken = $candidate->fresh()->remember_token;
        $this->useBrowserCookies([]);
        [$staff, $employeeCredentials, $employeePrefix] = $this->account('web');
        $employeeLogin = $this->postJson($employeePrefix.'/login', $employeeCredentials)->assertOk();
        $this->useBrowserCookies($this->cookiesFrom($employeeLogin) + [$recallerName => $applicantCookies[$recallerName]]);

        $logout = $this->postJson($employeePrefix.'/logout')->assertNoContent()->assertCookieExpired($recallerName);

        $this->assertNull($staff->fresh()->active_session_token);
        $this->assertSame($applicantLease, $candidate->fresh()->active_session_token);
        $this->assertSame($rememberToken, $candidate->fresh()->remember_token);
        $this->useBrowserCookies($this->cookiesFrom($logout));
        $this->getJson($applicantPrefix.'/me')->assertUnauthorized();
        $this->useBrowserCookies($applicantCookies);
        $this->getJson($applicantPrefix.'/me')->assertOk()->assertJsonPath('data.id', $candidate->id);
    }

    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_changed_password_returns_401_for_owner_and_frees_lease_for_fresh_credentials(string $guard): void
    {
        [$account, $credentials, $prefix] = $this->account($guard);
        $login = $this->postJson($prefix.'/login', $credentials + ['remember' => true])->assertOk();
        $cookies = $this->cookiesFrom($login);
        $account->update(['password' => 'replacement-password']);
        $this->useBrowserCookies($cookies);

        $this->getJson($prefix.'/me')->assertUnauthorized();

        $this->assertNull($account->fresh()->active_session_token);
        $this->assertNull($account->fresh()->active_session_last_activity_at);
        $this->useBrowserCookies([]);
        $fresh = $this->postJson($prefix.'/login', array_replace($credentials, ['password' => 'replacement-password']))->assertOk();
        $newLease = $account->fresh()->active_session_token;
        $this->useBrowserCookies($cookies);
        $this->getJson($prefix.'/me')->assertUnauthorized();
        $this->assertSame($newLease, $account->fresh()->active_session_token);
        $this->useBrowserCookies($this->cookiesFrom($fresh));
        $this->getJson($prefix.'/me')->assertOk();
    }

    public function test_password_invalidation_releases_both_guard_leases_without_old_cookies_stealing_fresh_logins(): void
    {
        [$staff, $employeeCredentials, $employeePrefix] = $this->account('web');
        [$candidate, $applicantCredentials, $applicantPrefix] = $this->account('applicant');
        $employeeLogin = $this->postJson($employeePrefix.'/login', $employeeCredentials + ['remember' => true])->assertOk();
        $this->useBrowserCookies($this->cookiesFrom($employeeLogin));
        $applicantLogin = $this->postJson($applicantPrefix.'/login', $applicantCredentials + ['remember' => true])->assertOk();
        $oldCookies = array_replace($this->cookiesFrom($employeeLogin), $this->cookiesFrom($applicantLogin));
        $staff->update(['password' => 'replacement-password']);
        $this->useBrowserCookies($oldCookies);

        $this->getJson($employeePrefix.'/me')->assertUnauthorized();

        $this->assertNull($staff->fresh()->active_session_token);
        $this->assertNull($candidate->fresh()->active_session_token);
        $this->useBrowserCookies([]);
        $freshEmployee = $this->postJson($employeePrefix.'/login', array_replace($employeeCredentials, ['password' => 'replacement-password']))->assertOk();
        $newEmployeeLease = $staff->fresh()->active_session_token;
        $this->useBrowserCookies([]);
        $freshApplicant = $this->postJson($applicantPrefix.'/login', $applicantCredentials)->assertOk();
        $newApplicantLease = $candidate->fresh()->active_session_token;
        $this->useBrowserCookies($oldCookies);
        $this->getJson($employeePrefix.'/me')->assertUnauthorized();
        $this->useBrowserCookies($oldCookies);
        $this->getJson($applicantPrefix.'/me')->assertUnauthorized();
        $this->assertSame($newEmployeeLease, $staff->fresh()->active_session_token);
        $this->assertSame($newApplicantLease, $candidate->fresh()->active_session_token);
        $this->useBrowserCookies($this->cookiesFrom($freshEmployee));
        $this->getJson($employeePrefix.'/me')->assertOk();
        $this->useBrowserCookies($this->cookiesFrom($freshApplicant));
        $this->getJson($applicantPrefix.'/me')->assertOk();
    }

    public function test_password_change_rotates_session_and_preserves_both_guard_owners(): void
    {
        [$staff, $credentials, $employeePrefix] = $this->account('web');
        [$candidate, $applicantCredentials, $applicantPrefix] = $this->account('applicant');
        $login = $this->postJson($employeePrefix.'/login', $credentials)->assertOk();
        $this->useBrowserCookies($this->cookiesFrom($login));
        $applicantLogin = $this->postJson($applicantPrefix.'/login', $applicantCredentials)->assertOk();
        $originalCookies = $this->cookiesFrom($applicantLogin);
        $applicantLease = $candidate->fresh()->active_session_token;
        $this->useBrowserCookies($originalCookies);

        $changed = $this->putJson($employeePrefix.'/password', [
            'current_password' => 'password', 'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ])->assertOk();

        $this->assertNotSame($originalCookies[config('session.cookie')], $this->cookiesFrom($changed)[config('session.cookie')]);
        $this->assertNotEmpty($staff->fresh()->active_session_token);
        $employeeLease = $staff->fresh()->active_session_token;
        $this->assertSame($applicantLease, $candidate->fresh()->active_session_token);
        $newCookies = $this->cookiesFrom($changed);
        $this->useBrowserCookies($newCookies);
        $this->getJson($employeePrefix.'/me')->assertOk();
        $this->useBrowserCookies($newCookies);
        $this->getJson($applicantPrefix.'/me')->assertOk();
        $this->useBrowserCookies($originalCookies);
        $this->getJson($employeePrefix.'/me')->assertUnauthorized();
        $this->useBrowserCookies($originalCookies);
        $this->getJson($applicantPrefix.'/me')->assertUnauthorized();
        $this->assertSame($employeeLease, $staff->fresh()->active_session_token);
        $this->assertSame($applicantLease, $candidate->fresh()->active_session_token);
        $this->useBrowserCookies($newCookies);
        $this->getJson($employeePrefix.'/me')->assertOk();
        $this->useBrowserCookies($newCookies);
        $this->getJson($applicantPrefix.'/me')->assertOk();
        $this->useBrowserCookies([]);
        $takeover = $this->postJson($employeePrefix.'/login', array_replace($credentials, ['password' => 'replacement-password']))->assertOk();
        $this->useBrowserCookies($newCookies);
        $this->getJson($employeePrefix.'/me')->assertUnauthorized();
        $this->useBrowserCookies($this->cookiesFrom($takeover));
        $this->getJson($employeePrefix.'/me')->assertOk();
    }

    /** @return array{0: User|Applicant, 1: array<string, string>, 2: string} */
    private function account(string $guard): array
    {
        if ($guard === 'applicant') {
            $account = Applicant::factory()->create();

            return [$account, ['email' => $account->email, 'password' => 'password'], '/api/v1/applicant/auth'];
        }

        $account = $this->user();
        $employee = $this->employee($account);

        return [$account, ['employee_number' => $employee->employee_number, 'password' => 'password'], '/api/v1/auth'];
    }

    /** @return array<string, string> */
    private function cookiesFrom(TestResponse $response): array
    {
        $cookies = [];

        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $cookies;
    }

    /** @param array<string, string> $cookies */
    private function useBrowserCookies(array $cookies): void
    {
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->withCredentials()->withUnencryptedCookies($cookies);
        $this->app['session']->driver()->flush();
        $this->app['auth']->forgetGuards();
    }
}
