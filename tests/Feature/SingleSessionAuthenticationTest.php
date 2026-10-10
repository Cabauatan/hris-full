<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\FormRequestTestCase;

class SingleSessionAuthenticationTest extends FormRequestTestCase
{
    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_second_browser_login_returns_409_without_displacing_first_browser(string $guard): void
    {
        [$account, $credentials, $prefix] = $this->account($guard);
        $this->freezeTime();
        $first = $this->postJson($prefix.'/login', $credentials + ['remember' => true])->assertOk();
        $firstCookies = $this->cookiesFrom($first);
        $originalLease = $account->fresh()->active_session_token;
        $originalRememberToken = $account->fresh()->remember_token;
        $originalLogin = $account->fresh()->last_login_at;
        $this->useBrowserCookies([]);

        $second = $this->postJson($prefix.'/login', $credentials + ['remember' => true])->assertConflict();

        $this->assertGuest($guard);
        $this->assertNotEmpty($originalLease);
        $this->assertSame($originalLease, $account->fresh()->active_session_token);
        $this->assertSame($originalRememberToken, $account->fresh()->remember_token);
        $this->assertTrue($originalLogin->equalTo($account->fresh()->last_login_at));
        $this->useBrowserCookies($this->cookiesFrom($second));
        $this->getJson($prefix.'/me')->assertUnauthorized();
        $this->useBrowserCookies($firstCookies);
        $this->getJson($prefix.'/me')->assertOk()->assertJsonPath('data.id', $account->id);
    }

    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_wrong_password_returns_generic_422_when_account_has_active_session(string $guard): void
    {
        [$account, $credentials, $prefix] = $this->account($guard);
        $first = $this->postJson($prefix.'/login', $credentials)->assertOk();
        $lease = $account->fresh()->active_session_token;
        $this->useBrowserCookies([]);
        $field = $guard === 'web' ? 'employee_number' : 'email';

        $this->postJson($prefix.'/login', array_replace($credentials, ['password' => 'wrong-password']))
            ->assertUnprocessable()->assertJsonValidationErrors($field)
            ->assertJsonPath('errors.'.$field.'.0', __('auth.failed'));

        $this->assertSame($lease, $account->fresh()->active_session_token);
        $this->useBrowserCookies($this->cookiesFrom($first));
        $this->getJson($prefix.'/me')->assertOk();
    }

    #[TestWith(['web'])]
    #[TestWith(['applicant'])]
    public function test_blocked_login_returns_409_without_rehashing_password_or_invalidating_owner(string $guard): void
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

        $this->postJson($prefix.'/login', $credentials)->assertConflict();

        $this->assertSame($originalHash, $account->fresh()->getAuthPassword());
        $this->useBrowserCookies($this->cookiesFrom($first));
        $this->getJson($prefix.'/me')->assertOk();
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
        $this->useBrowserCookies([]);

        $this->postJson($prefix.'/login', $credentials)->assertConflict();

        $this->assertSame($firstLease, $account->fresh()->active_session_token);
        $this->useBrowserCookies($this->cookiesFrom($renewed));
        $this->getJson($prefix.'/me')->assertOk();
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
        $this->postJson($prefix.'/login', $credentials)->assertConflict();
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

    public function test_applicant_registration_claims_a_session_and_second_login_returns_409(): void
    {
        $registration = $this->postJson('/api/v1/applicant/auth/register', [
            'first_name' => 'External', 'last_name' => 'Candidate', 'email' => 'registered@example.test',
            'password' => 'applicant-password', 'password_confirmation' => 'applicant-password',
        ])->assertCreated();
        $account = Applicant::query()->findOrFail($registration->json('data.id'));
        $this->assertNotEmpty($account->active_session_token);
        $this->useBrowserCookies([]);

        $this->postJson('/api/v1/applicant/auth/login', ['email' => $account->email, 'password' => 'applicant-password'])->assertConflict();

        $this->useBrowserCookies($this->cookiesFrom($registration));
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
        $this->postJson($employeePrefix.'/login', $employeeCredentials)->assertConflict();
        $this->useBrowserCookies([]);
        $this->postJson($applicantPrefix.'/login', $applicantCredentials)->assertOk();
    }

    public function test_blocked_applicant_login_preserves_employee_session_in_same_browser(): void
    {
        [$candidate, $applicantCredentials, $applicantPrefix] = $this->account('applicant');
        $applicantLogin = $this->postJson($applicantPrefix.'/login', $applicantCredentials)->assertOk();
        $applicantLease = $candidate->fresh()->active_session_token;
        $this->useBrowserCookies([]);
        [$staff, $employeeCredentials, $employeePrefix] = $this->account('web');
        $employeeLogin = $this->postJson($employeePrefix.'/login', $employeeCredentials)->assertOk();
        $employeeLease = $staff->fresh()->active_session_token;
        $this->useBrowserCookies($this->cookiesFrom($employeeLogin));

        $blocked = $this->postJson($applicantPrefix.'/login', $applicantCredentials)->assertConflict();

        $this->assertSame($employeeLease, $staff->fresh()->active_session_token);
        $this->assertSame($applicantLease, $candidate->fresh()->active_session_token);
        $this->useBrowserCookies($this->cookiesFrom($blocked));
        $this->getJson($employeePrefix.'/me')->assertOk();
        $this->useBrowserCookies($this->cookiesFrom($applicantLogin));
        $this->getJson($applicantPrefix.'/me')->assertOk();
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
        $this->postJson($employeePrefix.'/login', array_replace($credentials, ['password' => 'replacement-password']))->assertConflict();
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
