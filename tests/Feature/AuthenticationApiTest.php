<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class AuthenticationApiTest extends FormRequestTestCase
{
    public function test_csrf_bootstrap_returns_204_and_browser_cookies(): void
    {
        $response = $this->get('/api/v1/auth/csrf-cookie');

        $response->assertNoContent()->assertCookie('XSRF-TOKEN')
            ->assertCookie(config('session.cookie'));
        $cookie = $response->getCookie(config('session.cookie'), false);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertNotEmpty($response->getCookie('XSRF-TOKEN')->getValue());
    }

    #[DataProvider('protectedAuthenticationEndpoints')]
    public function test_protected_authentication_endpoints_return_json_401_for_guests(string $method, string $uri): void
    {
        $response = $this->json($method, $uri);

        $response->assertUnauthorized()->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_login_returns_only_explicit_account_identity_and_active_unique_access_codes(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 4)->setTime(8, 0, 0));
        $user = $this->user('organization.view', 'leave.create');
        $employee = $this->employee($user, [
            'employee_number' => 'AUTH-001',
            'first_name' => 'Login',
            'last_name' => 'Employee',
            'personal_email' => 'private@example.test',
            'mobile_number' => '09123456789',
            'address_line_1' => 'Private address',
        ]);
        $activeRole = Role::query()->create(['code' => 'employee', 'name' => 'Employee', 'is_active' => true]);
        $inactiveRole = Role::query()->create(['code' => 'retired-role', 'name' => 'Retired', 'is_active' => false]);
        $activePermission = $this->permission('attendance.view');
        $duplicatePermission = Permission::query()->where('code', 'organization.view')->firstOrFail();
        $inactivePermission = $this->permission('disabled.permission', false);
        $inactiveRolePermission = $this->permission('payroll.finalize');
        $activeRole->permissions()->attach([$activePermission->id, $duplicatePermission->id, $inactivePermission->id]);
        $inactiveRole->permissions()->attach($inactiveRolePermission->id);
        $user->roles()->attach([$activeRole->id, $inactiveRole->id]);
        $user->permissions()->attach($inactivePermission->id);
        $originalPassword = $user->password;

        $response = $this->postJson('/api/v1/auth/login', [
            'employee_number' => $this->employeeNumber($user),
            'password' => 'password',
            'is_active' => false,
            'roles' => ['administrator'],
            'permissions' => ['payroll.finalize'],
            'last_login_at' => '2000-01-01',
            'employee_id' => 999999,
        ]);

        $response->assertOk()->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.uuid', $user->uuid)
            ->assertJsonPath('data.name', $user->name)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.roles', ['employee'])
            ->assertJsonPath('data.permissions', ['attendance.view', 'leave.create', 'organization.view'])
            ->assertJsonPath('data.employee', [
                'id' => $employee->id,
                'employee_number' => 'AUTH-001',
                'first_name' => 'Login',
                'last_name' => 'Employee',
            ])
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');
        $accountFields = array_keys($response->json('data'));
        sort($accountFields);
        $this->assertSame(
            ['email', 'employee', 'id', 'is_active', 'last_login_at', 'name', 'permissions', 'roles', 'uuid'],
            $accountFields,
        );
        $this->assertSame($originalPassword, $user->fresh()->password);
        $this->assertSame('2026-10-04 08:00:00', $user->fresh()->last_login_at->format('Y-m-d H:i:s'));
        $this->assertTrue($user->fresh()->is_active);
        $this->assertAuthenticatedAs($user);
        $this->assertStringNotContainsString('private@example.test', $response->getContent());
        $this->assertStringNotContainsString('Private address', $response->getContent());
    }

    public function test_login_trims_employee_number_preserves_leading_zeroes_and_ignores_conflicting_email(): void
    {
        $user = $this->user();
        $otherUser = $this->user();
        $employee = $this->employee($user, ['employee_number' => '00001234']);
        $this->employee($otherUser, ['employee_number' => '00001235']);

        $response = $this->postJson('/api/v1/auth/login', [
            'employee_number' => '  00001234  ',
            'email' => $otherUser->email,
            'password' => 'password',
        ]);

        $response->assertOk()->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.employee.id', $employee->id)
            ->assertJsonPath('data.employee.employee_number', '00001234')
            ->assertJsonPath('data.roles', [])
            ->assertJsonPath('data.permissions', []);
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertNull($otherUser->fresh()->last_login_at);
    }

    public function test_unlinked_account_cannot_login_by_contact_email_with_422(): void
    {
        $user = $this->user();
        $user->update(['email' => 'unlinked@example.test']);

        $this->postJson('/api/v1/auth/login', [
            'employee_number' => $user->email,
            'email' => $user->email,
            'password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('employee_number')
            ->assertJsonPath('errors.employee_number.0', 'These credentials do not match our records.');

        $this->assertNull($user->fresh()->last_login_at);
        $this->assertGuest();
    }

    public function test_login_accepts_employee_number_at_fifty_character_boundary(): void
    {
        $user = $this->user();
        $employeeNumber = str_repeat('N', 50);
        $this->employee($user, ['employee_number' => $employeeNumber]);

        $this->postJson('/api/v1/auth/login', [
            'employee_number' => $employeeNumber,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.employee.employee_number', $employeeNumber);

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_employee_without_a_linked_user_cannot_login_with_422(): void
    {
        $user = $this->user();
        $this->employee(null, ['employee_number' => 'ORPHAN-001']);

        $this->postJson('/api/v1/auth/login', [
            'employee_number' => 'ORPHAN-001',
            'email' => $user->email,
            'password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('employee_number')
            ->assertJsonPath('errors.employee_number.0', 'These credentials do not match our records.');

        $this->assertNull($user->fresh()->last_login_at);
        $this->assertGuest();
    }

    public function test_employee_primary_key_cannot_be_used_in_place_of_employee_number_with_422(): void
    {
        $user = $this->user();
        $employee = $this->employee($user, ['employee_number' => 'NUMBER-001']);

        $this->postJson('/api/v1/auth/login', [
            'employee_number' => (string) $employee->id,
            'password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('employee_number')
            ->assertJsonPath('errors.employee_number.0', 'These credentials do not match our records.');

        $this->assertNull($user->fresh()->last_login_at);
        $this->assertGuest();
    }

    public function test_employee_number_sql_metacharacters_return_generic_422_without_matching_account(): void
    {
        $user = $this->user();
        $this->employee($user, ['employee_number' => 'BOUND-001']);

        $this->postJson('/api/v1/auth/login', [
            'employee_number' => "' OR 1=1 --",
            'password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('employee_number')
            ->assertJsonPath('errors.employee_number.0', 'These credentials do not match our records.')
            ->assertJsonMissingPath('data');

        $this->assertNull($user->fresh()->last_login_at);
        $this->assertGuest();
    }

    #[DataProvider('employeeChangesDuringLogin')]
    public function test_employee_changed_after_guard_attempt_returns_generic_422_and_clears_session(string $scenario): void
    {
        $user = $this->user();
        $employee = $this->employee($user, ['employee_number' => 'LOGIN-RACE-001']);
        $replacementUser = $scenario === 'reassigned' ? $this->user() : null;
        $interrupted = false;
        Event::listen(Login::class, function (Login $event) use ($user, $employee, $replacementUser, $scenario, &$interrupted): void {
            if ($interrupted || $event->guard !== 'web' || $event->user->getAuthIdentifier() !== $user->id) {
                return;
            }

            $interrupted = true;
            $employee->update(match ($scenario) {
                'renamed' => ['employee_number' => 'LOGIN-RACE-002'],
                'detached' => ['user_id' => null],
                'reassigned' => ['user_id' => $replacementUser->id],
                'inactive' => ['is_active' => false],
            });
        });

        $response = $this->postJson('/api/v1/auth/login', [
            'employee_number' => 'LOGIN-RACE-001',
            'password' => 'password',
            'remember' => true,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('employee_number')
            ->assertJsonPath('errors.employee_number.0', 'These credentials do not match our records.');
        $this->assertTrue($interrupted);
        $this->assertNull($user->fresh()->last_login_at);
        $this->assertGuest();
        $this->useBrowserCookies($this->cookiesFrom($response));
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_password_changed_after_guard_attempt_rejects_stale_credentials_with_422_and_no_session(): void
    {
        $user = $this->user();
        $interrupted = false;
        Event::listen(Login::class, function (Login $event) use ($user, &$interrupted): void {
            if ($interrupted || $event->guard !== 'web' || $event->user->getAuthIdentifier() !== $user->id) {
                return;
            }

            $interrupted = true;
            $user->fresh()->update(['password' => 'changed-during-login']);
        });

        $rejected = $this->postJson('/api/v1/auth/login', ['employee_number' => $this->employeeNumber($user), 'password' => 'password']);

        $rejected->assertUnprocessable()->assertJsonValidationErrors('employee_number')
            ->assertJsonPath('errors.employee_number.0', 'These credentials do not match our records.');
        $this->assertTrue($interrupted);
        $this->assertNull($user->fresh()->last_login_at);
        $this->assertGuest();
        $this->assertTrue(Hash::check('changed-during-login', $user->fresh()->password));
        $this->useBrowserCookies($this->cookiesFrom($rejected));
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->useBrowserCookies([]);
        $this->postJson('/api/v1/auth/login', [
            'employee_number' => $this->employeeNumber($user),
            'password' => 'changed-during-login',
        ])->assertOk()->assertJsonPath('data.id', $user->id);
        $this->assertAuthenticatedAs($user);
    }

    public function test_user_disabled_after_guard_attempt_returns_generic_422_and_clears_session(): void
    {
        $user = $this->user();
        $employee = $this->employee($user, ['employee_number' => 'USER-RACE-001']);
        $interrupted = false;
        Event::listen(Login::class, function (Login $event) use ($user, &$interrupted): void {
            if ($interrupted || $event->guard !== 'web' || $event->user->getAuthIdentifier() !== $user->id) {
                return;
            }

            $interrupted = true;
            $user->update(['is_active' => false]);
        });

        $response = $this->postJson('/api/v1/auth/login', [
            'employee_number' => $employee->employee_number,
            'password' => 'password',
            'remember' => true,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('employee_number')
            ->assertJsonPath('errors.employee_number.0', 'These credentials do not match our records.');
        $this->assertTrue($interrupted);
        $this->assertFalse($user->fresh()->is_active);
        $this->assertNull($user->fresh()->last_login_at);
        $this->assertGuest();
        $this->useBrowserCookies($this->cookiesFrom($response));
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    #[DataProvider('invalidCredentials')]
    public function test_invalid_or_inactive_credentials_return_same_generic_422_and_preserve_login_timestamp(string $scenario): void
    {
        $user = $this->user();
        $employee = $this->employee($user);
        if ($scenario === 'inactive') {
            $user->update(['is_active' => false]);
        }
        if ($scenario === 'inactive-employee') {
            $employee->update(['is_active' => false]);
        }
        $employeeNumber = $scenario === 'unknown' ? 'UNKNOWN-001' : $employee->employee_number;
        $password = $scenario === 'wrong-password' ? 'wrong-password' : 'password';

        $this->postJson('/api/v1/auth/login', ['employee_number' => $employeeNumber, 'password' => $password])
            ->assertUnprocessable()->assertJsonValidationErrors('employee_number')
            ->assertJsonPath('errors.employee_number.0', 'These credentials do not match our records.');

        $this->assertNull($user->fresh()->last_login_at);
        $this->assertGuest();
    }

    #[DataProvider('invalidLoginPayloads')]
    public function test_invalid_login_input_returns_json_422_without_authenticating(array $payload, array $fields): void
    {
        $this->postJson('/api/v1/auth/login', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($fields);

        $this->assertGuest();
    }

    public function test_remember_login_issues_recaller_cookie_without_exposing_token(): void
    {
        $user = $this->user();
        $recallerName = $this->app['auth']->guard('web')->getRecallerName();

        $response = $this->postJson('/api/v1/auth/login', [
            'employee_number' => $this->employeeNumber($user),
            'password' => 'password',
            'remember' => true,
        ]);

        $response->assertOk()->assertCookie($recallerName)
            ->assertJsonMissingPath('data.remember_token');
        $this->assertGreaterThan(now()->timestamp, $response->getCookie($recallerName, false)->getExpiresTime());
        $this->assertNotEmpty($user->fresh()->remember_token);
    }

    public function test_login_without_remember_does_not_issue_persistent_recaller_cookie(): void
    {
        $user = $this->user();
        $recallerName = $this->app['auth']->guard('web')->getRecallerName();

        $this->postJson('/api/v1/auth/login', [
            'employee_number' => $this->employeeNumber($user),
            'password' => 'password',
            'remember' => false,
        ])->assertOk()->assertCookieMissing($recallerName);
    }

    #[DataProvider('validBoundaryPasswords')]
    public function test_login_accepts_passwords_at_bcrypt_seventy_two_byte_boundary(string $password): void
    {
        $user = $this->user();
        $user->update(['password' => $password]);

        $this->postJson('/api/v1/auth/login', ['employee_number' => $this->employeeNumber($user), 'password' => $password])
            ->assertOk()->assertJsonPath('data.id', $user->id);

        $this->assertAuthenticatedAs($user);
    }

    #[DataProvider('validBoundaryPasswords')]
    public function test_password_change_accepts_seventy_two_byte_password_without_truncation(string $password): void
    {
        $user = $this->user();
        $login = $this->postJson('/api/v1/auth/login', ['employee_number' => $this->employeeNumber($user), 'password' => 'password']);
        $login->assertOk();
        $this->useBrowserCookies($this->cookiesFrom($login));

        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertOk();

        $this->assertTrue(Hash::check($password, $user->fresh()->password));
        $this->assertFalse(Hash::check('password', $user->fresh()->password));
    }

    #[DataProvider('validBoundaryPasswords')]
    public function test_current_password_suffix_beyond_bcrypt_byte_limit_returns_422_and_preserves_current_session(string $password): void
    {
        $user = $this->user();
        $user->update(['password' => $password]);
        $originalHash = $user->password;
        $originalToken = $user->remember_token;
        $login = $this->postJson('/api/v1/auth/login', ['employee_number' => $this->employeeNumber($user), 'password' => $password]);
        $login->assertOk();
        $this->useBrowserCookies($this->cookiesFrom($login));

        $response = $this->putJson('/api/v1/auth/password', [
            'current_password' => $password.'suffix',
            'password' => 'valid-new-password',
            'password_confirmation' => 'valid-new-password',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertSame($originalHash, $user->fresh()->password);
        $this->assertSame($originalToken, $user->fresh()->remember_token);
        $this->assertAuthenticatedAs($user);
        $this->useBrowserCookies($this->cookiesFrom($response));
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_real_login_session_authenticates_existing_api_and_me_after_guard_memory_is_cleared(): void
    {
        $user = $this->user('organization.view');
        $division = Division::query()->create(['code' => 'SESSION-DIVISION', 'name' => 'Session Division', 'is_active' => true]);
        $login = $this->postJson('/api/v1/auth/login', ['employee_number' => $this->employeeNumber($user), 'password' => 'password']);
        $login->assertOk();
        $cookies = $this->cookiesFrom($login);

        $this->useBrowserCookies($cookies);
        $this->getJson('/api/v1/divisions')->assertOk()->assertJsonPath('data.0.id', $division->id);
        $this->useBrowserCookies($cookies);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_authenticated_account_cannot_switch_identity_through_login_and_receives_409(): void
    {
        $user = $this->user();
        $otherUser = $this->user();
        $login = $this->postJson('/api/v1/auth/login', ['employee_number' => $this->employeeNumber($user), 'password' => 'password']);
        $login->assertOk();
        $this->useBrowserCookies($this->cookiesFrom($login));

        $this->postJson('/api/v1/auth/login', ['employee_number' => $this->employeeNumber($otherUser), 'password' => 'password'])
            ->assertConflict();

        $this->assertAuthenticatedAs($user);
        $this->assertNull($otherUser->fresh()->last_login_at);
    }

    public function test_login_context_keeps_complete_employee_profile_available_in_current_guard(): void
    {
        $user = $this->user();
        $employee = $this->employee($user, [
            'personal_email' => 'self-profile@example.test',
            'birth_date' => '1990-02-03',
        ]);
        $login = $this->postJson('/api/v1/auth/login', ['employee_number' => $this->employeeNumber($user), 'password' => 'password']);
        $login->assertOk()->assertJsonMissingPath('data.employee.personal_email')
            ->assertJsonMissingPath('data.employee.birth_date');
        $this->withCredentials()->withUnencryptedCookies($this->cookiesFrom($login));

        $this->getJson('/api/v1/ess/profile')->assertOk()
            ->assertJsonPath('data.id', $employee->id)
            ->assertJsonPath('data.personal_email', 'self-profile@example.test')
            ->assertJsonPath('data.birth_date', '1990-02-03');
    }

    public function test_logout_invalidates_authenticated_session_and_rotates_csrf_token(): void
    {
        $user = $this->user();
        $login = $this->postJson('/api/v1/auth/login', ['employee_number' => $this->employeeNumber($user), 'password' => 'password']);
        $login->assertOk();
        $oldCookies = $this->cookiesFrom($login);
        $oldToken = $login->getCookie('XSRF-TOKEN')->getValue();
        $this->useBrowserCookies($oldCookies);

        $logout = $this->postJson('/api/v1/auth/logout');

        $logout->assertNoContent();
        $this->assertGuest();
        $this->assertNotSame($oldToken, $logout->getCookie('XSRF-TOKEN')->getValue());
        $this->useBrowserCookies($oldCookies);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_disabled_account_session_returns_403_for_context_and_api_but_can_logout(): void
    {
        $user = $this->user('organization.view');
        $login = $this->postJson('/api/v1/auth/login', ['employee_number' => $this->employeeNumber($user), 'password' => 'password']);
        $login->assertOk();
        $cookies = $this->cookiesFrom($login);
        $user->update(['is_active' => false]);
        $this->useBrowserCookies($cookies);

        $this->getJson('/api/v1/auth/me')->assertForbidden();
        $this->useBrowserCookies($cookies);
        $this->getJson('/api/v1/divisions')->assertForbidden();
        $this->useBrowserCookies($cookies);
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertGuest();
    }

    public function test_password_change_hashes_new_password_rotates_token_and_retains_current_login(): void
    {
        $user = $this->user();
        $oldHash = $user->password;
        $oldRememberToken = $user->remember_token;
        $login = $this->postJson('/api/v1/auth/login', ['employee_number' => $this->employeeNumber($user), 'password' => 'password']);
        $login->assertOk();
        $this->useBrowserCookies($this->cookiesFrom($login));

        $response = $this->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'new-password-2026',
            'password_confirmation' => 'new-password-2026',
            'is_active' => false,
            'roles' => ['administrator'],
        ]);

        $response->assertOk();
        $user->refresh();
        $this->assertTrue(Hash::check('new-password-2026', $user->password));
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertNotSame($oldHash, $user->password);
        $this->assertNotSame($oldRememberToken, $user->remember_token);
        $this->assertTrue($user->is_active);
        $this->assertAuthenticatedAs($user);
        $this->useBrowserCookies($this->cookiesFrom($response));
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    #[DataProvider('invalidPasswordChanges')]
    public function test_invalid_password_change_returns_422_and_preserves_password_and_remember_token(array $overrides, array $fields): void
    {
        $user = $this->user();
        $originalHash = $user->password;
        $originalToken = $user->remember_token;
        $login = $this->postJson('/api/v1/auth/login', ['employee_number' => $this->employeeNumber($user), 'password' => 'password']);
        $login->assertOk();
        $this->useBrowserCookies($this->cookiesFrom($login));

        $this->putJson('/api/v1/auth/password', array_replace([
            'current_password' => 'password',
            'password' => 'new-password-2026',
            'password_confirmation' => 'new-password-2026',
        ], $overrides))->assertUnprocessable()->assertJsonValidationErrors($fields);

        $this->assertSame($originalHash, $user->fresh()->password);
        $this->assertSame($originalToken, $user->fresh()->remember_token);
    }

    public function test_password_change_invalidates_other_established_sessions(): void
    {
        $user = $this->user();
        $firstLogin = $this->postJson('/api/v1/auth/login', ['employee_number' => $this->employeeNumber($user), 'password' => 'password']);
        $firstLogin->assertOk();
        $firstCookies = $this->cookiesFrom($firstLogin);
        $this->useBrowserCookies($firstCookies);
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->useBrowserCookies([]);
        $secondLogin = $this->historicalLogin($user);
        $secondCookies = $this->cookiesFrom($secondLogin);
        $this->useBrowserCookies($secondCookies);
        $this->getJson('/api/testing/historical-auth/me')->assertNoContent();
        $this->useBrowserCookies($firstCookies);
        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ])->assertOk();

        $this->useBrowserCookies($secondCookies);
        $this->getJson('/api/testing/historical-auth/me')->assertUnauthorized();
    }

    public function test_password_change_invalidates_other_login_session_before_its_first_protected_request(): void
    {
        $user = $this->user();
        $firstLogin = $this->postJson('/api/v1/auth/login', ['employee_number' => $this->employeeNumber($user), 'password' => 'password']);
        $firstLogin->assertOk();
        $firstCookies = $this->cookiesFrom($firstLogin);
        $this->useBrowserCookies([]);
        $secondLogin = $this->historicalLogin($user);
        $secondCookies = $this->cookiesFrom($secondLogin);
        $this->useBrowserCookies($firstCookies);

        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ])->assertOk();

        $this->useBrowserCookies($secondCookies);
        $this->getJson('/api/testing/historical-auth/me')->assertUnauthorized();
    }

    public function test_login_employee_number_and_ip_limit_returns_429_then_expires_after_one_minute(): void
    {
        $this->freezeTime();
        $user = $this->user();
        $payload = ['employee_number' => $this->employeeNumber($user), 'password' => 'wrong-password'];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', $payload)->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', $payload)->assertTooManyRequests()
            ->assertHeader('Retry-After');
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/login', $payload)->assertUnprocessable();

        $this->assertNull($user->fresh()->last_login_at);
        $this->assertGuest();
    }

    public function test_login_limit_normalizes_employee_number_and_cannot_be_bypassed_by_changing_email_with_429(): void
    {
        $this->freezeTime();
        $user = $this->user();
        $this->employee($user, ['employee_number' => 'LIMIT-001']);

        foreach (['LIMIT-001', 'limit-001', ' LIMIT-001 ', ' limit-001 ', 'Limit-001'] as $attempt => $employeeNumber) {
            $this->postJson('/api/v1/auth/login', [
                'employee_number' => $employeeNumber,
                'email' => 'attempt-'.$attempt.'@example.test',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', [
            'employee_number' => 'LIMIT-001',
            'email' => 'another-email@example.test',
            'password' => 'password',
        ])->assertTooManyRequests()->assertHeader('Retry-After');

        $this->assertNull($user->fresh()->last_login_at);
        $this->assertGuest();
    }

    public function test_login_employee_number_limit_is_scoped_to_client_ip(): void
    {
        $this->freezeTime();
        $user = $this->user();
        $employee = $this->employee($user, ['employee_number' => 'IP-001']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'employee_number' => $employee->employee_number,
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.25'])->postJson('/api/v1/auth/login', [
            'employee_number' => $employee->employee_number,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('data.id', $user->id);

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_ip_limit_blocks_employee_number_rotation_after_thirty_attempts_with_429(): void
    {
        $this->freezeTime();
        $this->user();

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'employee_number' => 'ATTEMPT-'.$attempt,
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', [
            'employee_number' => 'NEXT-EMPLOYEE',
            'password' => 'wrong-password',
        ])->assertTooManyRequests()->assertHeader('Retry-After');

        $this->assertGuest();
    }

    public function test_real_csrf_middleware_returns_419_for_login_without_browser_token(): void
    {
        $user = $this->user();
        $this->app->instance('env', 'local');

        try {
            $this->postJson('/api/v1/auth/login', ['employee_number' => $this->employeeNumber($user), 'password' => 'password'])
                ->assertStatus(419)->assertHeader('Content-Type', 'application/json');
            $this->assertGuest();
            $this->assertNull($user->fresh()->last_login_at);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_real_csrf_bootstrap_allows_login_with_encrypted_xsrf_header(): void
    {
        $user = $this->user();
        $this->app->instance('env', 'local');

        try {
            $csrf = $this->get('/api/v1/auth/csrf-cookie');
            $csrf->assertNoContent();
            $this->useBrowserCookies($this->cookiesFrom($csrf));
            $xsrfToken = $csrf->getCookie('XSRF-TOKEN', false)->getValue();

            $this->postJson('/api/v1/auth/login', ['employee_number' => $this->employeeNumber($user), 'password' => 'password'], [
                'X-XSRF-TOKEN' => $xsrfToken,
            ])->assertOk()->assertJsonPath('data.id', $user->id);
            $this->assertAuthenticatedAs($user);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_real_csrf_middleware_protects_existing_api_writes_with_419(): void
    {
        $user = $this->user('organization.manage');
        $login = $this->postJson('/api/v1/auth/login', ['employee_number' => $this->employeeNumber($user), 'password' => 'password']);
        $login->assertOk();
        $this->useBrowserCookies($this->cookiesFrom($login));
        $this->app->instance('env', 'local');

        try {
            $this->postJson('/api/v1/divisions', [
                'code' => 'CSRF-ATTACK',
                'name' => 'Should not exist',
                'is_active' => true,
            ])->assertStatus(419);
            $this->assertDatabaseMissing('divisions', ['code' => 'CSRF-ATTACK']);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    /** @return array<string, array{string, string}> */
    public static function protectedAuthenticationEndpoints(): array
    {
        return [
            'me' => ['GET', '/api/v1/auth/me'],
            'logout' => ['POST', '/api/v1/auth/logout'],
            'password' => ['PUT', '/api/v1/auth/password'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function invalidCredentials(): array
    {
        return [
            'wrong password' => ['wrong-password'],
            'unknown account' => ['unknown'],
            'inactive account' => ['inactive'],
            'inactive employee' => ['inactive-employee'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function employeeChangesDuringLogin(): array
    {
        return [
            'employee number changed' => ['renamed'],
            'employee detached from user' => ['detached'],
            'employee reassigned to another user' => ['reassigned'],
            'employee disabled' => ['inactive'],
        ];
    }

    /** @return array<string, array{array<string, mixed>, list<string>}> */
    public static function invalidLoginPayloads(): array
    {
        return [
            'missing credentials' => [[], ['employee_number', 'password']],
            'missing employee number' => [['password' => 'password'], ['employee_number']],
            'email only' => [['email' => 'valid@example.test', 'password' => 'password'], ['employee_number']],
            'empty employee number' => [['employee_number' => '', 'password' => 'password'], ['employee_number']],
            'whitespace employee number' => [['employee_number' => '   ', 'password' => 'password'], ['employee_number']],
            'array employee number' => [['employee_number' => ['VALID-001'], 'password' => 'password'], ['employee_number']],
            'numeric employee number' => [['employee_number' => 1234, 'password' => 'password'], ['employee_number']],
            'array password' => [['employee_number' => 'VALID-001', 'password' => ['password']], ['password']],
            'array remember' => [['employee_number' => 'VALID-001', 'password' => 'password', 'remember' => [true]], ['remember']],
            'invalid remember' => [['employee_number' => 'VALID-001', 'password' => 'password', 'remember' => 'sometimes'], ['remember']],
            'oversize employee number' => [['employee_number' => str_repeat('a', 51), 'password' => 'password'], ['employee_number']],
            'seventy three byte password' => [['employee_number' => 'VALID-001', 'password' => str_repeat('a', 73)], ['password']],
            'multibyte password exceeds byte capacity' => [['employee_number' => 'VALID-001', 'password' => str_repeat('ñ', 37)], ['password']],
            'password contains NUL' => [['employee_number' => 'VALID-001', 'password' => "pass\0word"], ['password']],
        ];
    }

    /** @return array<string, array{array<string, mixed>, list<string>}> */
    public static function invalidPasswordChanges(): array
    {
        return [
            'wrong current password' => [['current_password' => 'incorrect'], ['current_password']],
            'missing current password' => [['current_password' => null], ['current_password']],
            'short password' => [['password' => '1234567', 'password_confirmation' => '1234567'], ['password']],
            'confirmation mismatch' => [['password_confirmation' => 'different-password'], ['password']],
            'same password' => [['password' => 'password', 'password_confirmation' => 'password'], ['password']],
            'array password' => [['password' => ['new-password-2026']], ['password']],
            'seventy three byte password' => [['password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)], ['password']],
            'multibyte password exceeds byte capacity' => [['password' => str_repeat('ñ', 37), 'password_confirmation' => str_repeat('ñ', 37)], ['password']],
            'password contains NUL' => [['password' => "new\0password", 'password_confirmation' => "new\0password"], ['password']],
        ];
    }

    /** @return array<string, array{string}> */
    public static function validBoundaryPasswords(): array
    {
        return [
            'ASCII seventy two bytes' => [str_repeat('a', 72)],
            'multibyte seventy two bytes' => [str_repeat('ñ', 36)],
        ];
    }

    private function employeeNumber(User $user): string
    {
        return ($user->employee()->first() ?? $this->employee($user))->employee_number;
    }

    private function permission(string $code, bool $isActive = true): Permission
    {
        return Permission::query()->create(['code' => $code, 'name' => $code, 'is_active' => $isActive]);
    }

    private function historicalLogin(User $user): TestResponse
    {
        $this->app['router']->middleware('web')->post('/api/testing/historical-auth/login', function () use ($user) {
            $guard = auth('web');
            $guard->login($user);
            request()->session()->put('password_hash_web', $guard->hashPasswordForCookie($user->getAuthPassword()));

            return response()->noContent();
        });
        $this->app['router']->middleware(['web', 'auth:web', 'auth.session'])->get('/api/testing/historical-auth/me', fn () => response()->noContent());

        return $this->postJson('/api/testing/historical-auth/login')->assertNoContent();
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
