<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\FormRequestTestCase;

class ApplicantAuthenticationApiTest extends FormRequestTestCase
{
    public function test_csrf_bootstrap_returns_browser_cookies_with_secure_session_attributes(): void
    {
        $response = $this->get('/api/v1/applicant/auth/csrf-cookie');

        $response->assertNoContent()->assertCookie('XSRF-TOKEN')->assertCookie(config('session.cookie'));
        $this->assertTrue($response->getCookie(config('session.cookie'), false)->isHttpOnly());
        $this->assertSame('lax', $response->getCookie(config('session.cookie'), false)->getSameSite());
    }

    #[TestWith(['GET', '/api/v1/applicant/auth/me'])]
    #[TestWith(['POST', '/api/v1/applicant/auth/logout'])]
    public function test_protected_authentication_endpoint_returns_json_401_for_guests(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized()->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_registration_creates_only_an_applicant_and_returns_safe_normalized_identity(): void
    {
        $this->travelTo('2026-10-06 08:00:00');
        $staff = $this->user();
        $this->employee($staff);
        $userCount = User::query()->count();
        $employeeCount = Employee::query()->count();

        $response = $this->postJson('/api/v1/applicant/auth/register', self::registrationInput() + [
            'uuid' => 'attacker-uuid', 'is_active' => false, 'employee_number' => 'ATTACK-001',
            'roles' => ['super_admin'], 'permissions' => ['recruitment.manage'], 'status' => 'hired',
            'last_login_at' => '2000-01-01', 'user_id' => $staff->id,
        ]);

        $response->assertCreated()->assertJsonPath('data.first_name', 'External')->assertJsonPath('data.middle_name', 'Applicant')
            ->assertJsonPath('data.last_name', 'Candidate')->assertJsonPath('data.name', 'External Applicant Candidate')
            ->assertJsonPath('data.email', 'external@example.test')->assertJsonPath('data.mobile_number', '09123456789')
            ->assertJsonPath('data.is_active', true);
        $candidate = Applicant::query()->findOrFail($response->json('data.id'));
        $this->assertTrue(Str::isUuid($candidate->uuid));
        $this->assertTrue(Hash::check('applicant-password', $candidate->password));
        $this->assertSame('2026-10-06 08:00:00', $candidate->last_login_at->format('Y-m-d H:i:s'));
        $this->assertAuthenticatedAs($candidate, 'applicant');
        $this->assertGuest('web');
        $this->assertSame($userCount, User::query()->count());
        $this->assertSame($employeeCount, Employee::query()->count());
        $this->assertDatabaseCount('applicants', 1);
        $this->assertSafeIdentity($response->json('data'));
    }

    public function test_applicant_can_register_with_the_same_contact_email_as_an_employee_without_sharing_identity(): void
    {
        $staff = $this->user();

        $response = $this->postJson('/api/v1/applicant/auth/register', array_replace(self::registrationInput(), ['email' => $staff->email]));

        $response->assertCreated()->assertJsonPath('data.email', $staff->email);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('applicants', 1);
        $this->assertGuest('web');
    }

    public function test_duplicate_normalized_applicant_email_returns_422_and_preserves_existing_credentials(): void
    {
        $candidate = Applicant::factory()->create(['email' => 'external@example.test']);
        $originalHash = $candidate->password;

        $this->postJson('/api/v1/applicant/auth/register', self::registrationInput())
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('applicants', 1);
        $this->assertSame($originalHash, $candidate->fresh()->password);
        $this->assertGuest('applicant');
    }

    public function test_registration_accepts_optional_middle_name_and_mobile_number_absent(): void
    {
        $payload = self::registrationInput();
        unset($payload['middle_name'], $payload['mobile_number']);

        $this->postJson('/api/v1/applicant/auth/register', $payload)->assertCreated()
            ->assertJsonPath('data.name', 'External Candidate')->assertJsonPath('data.middle_name', null)
            ->assertJsonPath('data.mobile_number', null);

        $this->assertDatabaseHas('applicants', ['email' => 'external@example.test', 'middle_name' => null, 'mobile_number' => null]);
    }

    #[TestWith([72, 'a'])]
    #[TestWith([36, 'é'])]
    public function test_registration_and_login_accept_password_at_bcrypt_seventy_two_byte_boundary(int $count, string $character): void
    {
        $password = str_repeat($character, $count);
        $registration = $this->postJson('/api/v1/applicant/auth/register', array_replace(self::registrationInput(), [
            'password' => $password, 'password_confirmation' => $password,
        ]))->assertCreated();
        $candidate = Applicant::query()->findOrFail($registration->json('data.id'));
        $this->assertTrue(Hash::check($password, $candidate->password));
        $this->useBrowserCookies($this->cookiesFrom($registration));
        $logout = $this->postJson('/api/v1/applicant/auth/logout')->assertNoContent();
        $this->useBrowserCookies($this->cookiesFrom($logout));

        $this->postJson('/api/v1/applicant/auth/login', ['email' => $candidate->email, 'password' => $password])
            ->assertOk()->assertJsonPath('data.id', $candidate->id);
    }

    #[DataProvider('invalidRegistrations')]
    public function test_invalid_registration_returns_422_without_creating_an_account(array $overrides, array $fields): void
    {
        $this->postJson('/api/v1/applicant/auth/register', array_replace(self::registrationInput(), $overrides))
            ->assertUnprocessable()->assertJsonValidationErrors($fields);

        $this->assertDatabaseCount('applicants', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('employees', 0);
        $this->assertGuest('applicant');
    }

    public static function invalidRegistrations(): array
    {
        return [
            'required names' => [['first_name' => '', 'last_name' => ''], ['first_name', 'last_name']],
            'invalid email' => [['email' => 'not-an-email'], ['email']],
            'array email' => [['email' => ['external@example.test']], ['email']],
            'password confirmation mismatch' => [['password_confirmation' => 'different-password'], ['password']],
            'password too short' => [['password' => 'short', 'password_confirmation' => 'short'], ['password']],
            'password beyond bcrypt bytes' => [['password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)], ['password']],
            'multibyte password beyond bcrypt bytes' => [['password' => str_repeat('é', 37), 'password_confirmation' => str_repeat('é', 37)], ['password']],
            'password with null byte' => [['password' => "valid\0password", 'password_confirmation' => "valid\0password"], ['password']],
            'array password' => [['password' => ['applicant-password']], ['password']],
            'oversized name' => [['first_name' => str_repeat('A', 101)], ['first_name']],
        ];
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_login_normalizes_email_rotates_session_and_returns_safe_identity(bool $remember): void
    {
        $this->travelTo('2026-10-06 08:30:00');
        $candidate = Applicant::factory()->create(['email' => 'candidate@example.test']);
        $csrf = $this->get('/api/v1/applicant/auth/csrf-cookie')->assertNoContent();
        $initialSession = $csrf->getCookie(config('session.cookie'))->getValue();
        $this->useBrowserCookies($this->cookiesFrom($csrf));

        $response = $this->postJson('/api/v1/applicant/auth/login', [
            'email' => '  CANDIDATE@EXAMPLE.TEST  ', 'password' => 'password', 'remember' => $remember,
        ]);

        $response->assertOk()->assertJsonPath('data.id', $candidate->id)->assertJsonPath('data.email', 'candidate@example.test');
        $this->assertNotSame($initialSession, $response->getCookie(config('session.cookie'))->getValue());
        $this->assertSame('2026-10-06 08:30:00', $candidate->fresh()->last_login_at->format('Y-m-d H:i:s'));
        $this->assertAuthenticatedAs($candidate, 'applicant');
        $this->assertGuest('web');
        $this->assertSafeIdentity($response->json('data'));
        $recaller = auth('applicant')->getRecallerName();

        if ($remember) {
            $response->assertCookie($recaller);
        } else {
            $response->assertCookieMissing($recaller);
        }
    }

    public function test_real_applicant_cookie_authenticates_me_after_guard_memory_is_cleared(): void
    {
        $candidate = Applicant::factory()->create();
        $login = $this->postJson('/api/v1/applicant/auth/login', ['email' => $candidate->email, 'password' => 'password'])->assertOk();

        $this->useBrowserCookies($this->cookiesFrom($login));
        $response = $this->getJson('/api/v1/applicant/auth/me');

        $response->assertOk()->assertJsonPath('data.id', $candidate->id);
        $this->assertSafeIdentity($response->json('data'));
    }

    #[TestWith(['unknown'])]
    #[TestWith(['wrong-password'])]
    #[TestWith(['inactive'])]
    public function test_invalid_login_uses_generic_email_error_and_does_not_update_last_login(string $scenario): void
    {
        $candidate = Applicant::factory()->create();
        $email = $candidate->email;
        $password = 'password';

        if ($scenario === 'unknown') {
            $email = 'missing@example.test';
        } elseif ($scenario === 'inactive') {
            $candidate->update(['is_active' => false]);
        } else {
            $password = 'wrong-password';
        }

        $this->postJson('/api/v1/applicant/auth/login', ['email' => $email, 'password' => $password])
            ->assertUnprocessable()->assertJsonValidationErrors('email')->assertJsonPath('errors.email.0', __('auth.failed'));

        $this->assertNull($candidate->fresh()->last_login_at);
        $this->assertGuest('applicant');
    }

    public function test_employee_contact_email_and_password_are_not_accepted_as_applicant_credentials(): void
    {
        $staff = $this->user();
        $this->employee($staff);

        $this->postJson('/api/v1/applicant/auth/login', ['email' => $staff->email, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email')->assertJsonPath('errors.email.0', __('auth.failed'));

        $this->assertGuest('applicant');
        $this->assertGuest('web');
        $this->assertDatabaseCount('applicants', 0);
    }

    public function test_bearer_header_does_not_authenticate_the_session_only_applicant_api(): void
    {
        $candidate = Applicant::factory()->create();

        $this->withToken($candidate->uuid)->getJson('/api/v1/applicant/auth/me')->assertUnauthorized();

        $this->assertGuest('applicant');
    }

    #[DataProvider('invalidLoginInputs')]
    public function test_invalid_login_input_returns_422(array $payload, string $field): void
    {
        $this->postJson('/api/v1/applicant/auth/login', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertGuest('applicant');
    }

    public static function invalidLoginInputs(): array
    {
        return [
            'missing email' => [['password' => 'password'], 'email'],
            'array email' => [['email' => ['candidate@example.test'], 'password' => 'password'], 'email'],
            'array password' => [['email' => 'candidate@example.test', 'password' => ['password']], 'password'],
            'array remember' => [['email' => 'candidate@example.test', 'password' => 'password', 'remember' => ['yes']], 'remember'],
            'password null byte' => [['email' => 'candidate@example.test', 'password' => "pass\0word"], 'password'],
            'password over 72 bytes' => [['email' => 'candidate@example.test', 'password' => str_repeat('a', 73)], 'password'],
        ];
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_password_or_active_state_change_during_login_returns_422_and_clears_only_applicant_session(bool $disable): void
    {
        $staff = $this->user();
        $employee = $this->employee($staff);
        $employeeLogin = $this->postJson('/api/v1/auth/login', ['employee_number' => $employee->employee_number, 'password' => 'password'])->assertOk();
        $this->useBrowserCookies($this->cookiesFrom($employeeLogin));
        $candidate = Applicant::factory()->create();
        Event::listen(Login::class, function (Login $event) use ($candidate, $disable): void {
            if ($event->guard === 'applicant') {
                $candidate->update($disable ? ['is_active' => false] : ['password' => 'replacement-password']);
            }
        });

        $failure = $this->postJson('/api/v1/applicant/auth/login', ['email' => $candidate->email, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email')->assertJsonPath('errors.email.0', __('auth.failed'));

        $this->assertGuest('applicant');
        $this->assertNull($candidate->fresh()->last_login_at);
        $this->useBrowserCookies($this->cookiesFrom($failure));
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $staff->id);
    }

    #[TestWith(['register'])]
    #[TestWith(['login'])]
    public function test_existing_applicant_session_cannot_switch_identity_and_receives_409(string $action): void
    {
        $candidate = Applicant::factory()->create();
        $other = Applicant::factory()->create();
        $login = $this->postJson('/api/v1/applicant/auth/login', ['email' => $candidate->email, 'password' => 'password'])->assertOk();
        $this->useBrowserCookies($this->cookiesFrom($login));
        $payload = $action === 'login' ? ['email' => $other->email, 'password' => 'password'] : self::registrationInput();

        $this->postJson('/api/v1/applicant/auth/'.$action, $payload)->assertConflict();

        $this->assertAuthenticatedAs($candidate, 'applicant');
        $this->assertNull($other->fresh()->last_login_at);
        $this->assertDatabaseCount('applicants', 2);
    }

    public function test_disabled_applicant_session_receives_403_for_me_and_can_logout(): void
    {
        $candidate = Applicant::factory()->create();
        $login = $this->postJson('/api/v1/applicant/auth/login', ['email' => $candidate->email, 'password' => 'password'])->assertOk();
        $cookies = $this->cookiesFrom($login);
        $candidate->update(['is_active' => false]);

        $this->useBrowserCookies($cookies);
        $this->getJson('/api/v1/applicant/auth/me')->assertForbidden();
        $this->useBrowserCookies($cookies);
        $this->postJson('/api/v1/applicant/auth/logout')->assertNoContent();

        $this->assertGuest('applicant');
    }

    public function test_applicant_logout_preserves_a_real_employee_session_and_removes_applicant_identity(): void
    {
        $staff = $this->user();
        $employee = $this->employee($staff);
        $employeeLogin = $this->postJson('/api/v1/auth/login', ['employee_number' => $employee->employee_number, 'password' => 'password'])->assertOk();
        $this->useBrowserCookies($this->cookiesFrom($employeeLogin));
        $registration = $this->postJson('/api/v1/applicant/auth/register', self::registrationInput())->assertCreated();
        $bothCookies = $this->cookiesFrom($registration);
        $this->useBrowserCookies($bothCookies);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $staff->id);
        $this->useBrowserCookies($bothCookies);
        $this->getJson('/api/v1/applicant/auth/me')->assertOk()->assertJsonPath('data.id', $registration->json('data.id'));
        $this->useBrowserCookies($bothCookies);

        $logout = $this->postJson('/api/v1/applicant/auth/logout')->assertNoContent();

        $this->useBrowserCookies($this->cookiesFrom($logout));
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $staff->id);
        $this->useBrowserCookies($this->cookiesFrom($logout));
        $this->getJson('/api/v1/applicant/auth/me')->assertUnauthorized();
    }

    public function test_applicant_login_rate_limit_normalizes_email_and_expires_after_one_minute(): void
    {
        $this->freezeTime();
        $candidate = Applicant::factory()->create(['email' => 'candidate@example.test']);

        foreach (['candidate@example.test', 'CANDIDATE@EXAMPLE.TEST', ' candidate@example.test ', 'Candidate@example.test', ' CANDIDATE@EXAMPLE.TEST '] as $email) {
            $this->postJson('/api/v1/applicant/auth/login', ['email' => $email, 'password' => 'wrong-password'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/applicant/auth/login', ['email' => $candidate->email, 'password' => 'password'])
            ->assertTooManyRequests()->assertHeader('Retry-After');
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/applicant/auth/login', ['email' => $candidate->email, 'password' => 'password'])->assertOk();
    }

    public function test_applicant_login_ip_limit_blocks_email_rotation_after_thirty_attempts(): void
    {
        $this->freezeTime();

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson('/api/v1/applicant/auth/login', ['email' => 'attempt-'.$attempt.'@example.test', 'password' => 'wrong-password'])
                ->assertUnprocessable();
        }

        $this->postJson('/api/v1/applicant/auth/login', ['email' => 'next@example.test', 'password' => 'wrong-password'])
            ->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertGuest('applicant');
    }

    public function test_applicant_login_throttle_does_not_consume_employee_login_budget(): void
    {
        $this->freezeTime();
        $staff = $this->user();
        $employee = $this->employee($staff);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/applicant/auth/login', ['email' => $staff->email, 'password' => 'wrong-password'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', ['employee_number' => $employee->employee_number, 'password' => 'password'])
            ->assertOk()->assertJsonPath('data.id', $staff->id);
        $this->assertGuest('applicant');
    }

    public function test_registration_rate_limit_rejects_sixth_attempt_without_creating_accounts(): void
    {
        $this->freezeTime();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/applicant/auth/register', [])->assertUnprocessable();
        }

        $this->postJson('/api/v1/applicant/auth/register', self::registrationInput())->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertDatabaseCount('applicants', 0);
    }

    #[TestWith(['register'])]
    #[TestWith(['login'])]
    public function test_actual_csrf_middleware_rejects_authentication_without_browser_token_with_419(string $action): void
    {
        $candidate = Applicant::factory()->create();
        $payload = $action === 'register' ? self::registrationInput() : ['email' => $candidate->email, 'password' => 'password'];
        $this->app->instance('env', 'local');

        try {
            $this->postJson('/api/v1/applicant/auth/'.$action, $payload)->assertStatus(419)->assertHeader('Content-Type', 'application/json');
            $this->assertGuest('applicant');
            $this->assertNull($candidate->fresh()->last_login_at);
            $this->assertDatabaseCount('applicants', 1);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_actual_csrf_bootstrap_allows_registration_with_encrypted_xsrf_header(): void
    {
        $this->assertDatabaseCount('applicants', 0);
        $this->app->instance('env', 'local');

        try {
            $csrf = $this->get('/api/v1/applicant/auth/csrf-cookie')->assertNoContent();
            $this->useBrowserCookies($this->cookiesFrom($csrf));

            $this->postJson('/api/v1/applicant/auth/register', self::registrationInput(), [
                'X-XSRF-TOKEN' => $csrf->getCookie('XSRF-TOKEN', false)->getValue(),
            ])->assertCreated();
            $this->assertAuthenticated('applicant');
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function registrationInput(): array
    {
        return [
            'first_name' => 'External', 'middle_name' => 'Applicant', 'last_name' => 'Candidate',
            'email' => '  EXTERNAL@EXAMPLE.TEST  ', 'mobile_number' => '09123456789',
            'password' => 'applicant-password', 'password_confirmation' => 'applicant-password',
        ];
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    private function assertSafeIdentity(array $identity): void
    {
        $keys = array_keys($identity);
        sort($keys);
        $this->assertSame(
            ['email', 'first_name', 'id', 'is_active', 'last_login_at', 'last_name', 'middle_name', 'mobile_number', 'name', 'uuid'],
            $keys,
        );
    }

    /**
     * @return array<string, string>
     */
    private function cookiesFrom(TestResponse $response): array
    {
        $cookies = [];

        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $cookies;
    }

    /**
     * @param  array<string, string>  $cookies
     */
    private function useBrowserCookies(array $cookies): void
    {
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->withCredentials()->withUnencryptedCookies($cookies);
        $this->app['session']->driver()->flush();
        $this->app['auth']->forgetGuards();
    }
}
