<?php

namespace Tests;

use App\Models\Applicant;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\User;
use App\Services\Auth\SingleSessionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

abstract class FormRequestTestCase extends TestCase
{
    use LazilyRefreshDatabase;

    /** @var array<string, Authenticatable> */
    private array $impersonatedAccounts = [];

    public function actingAs(Authenticatable $user, $guard = null): static
    {
        $guardName = $guard ?? $this->app['auth']->getDefaultDriver();
        $previous = $this->impersonatedAccounts[$guardName] ?? $this->app['auth']->guard($guardName)->user();

        if ($previous !== null && ($previous::class !== $user::class || $previous->getAuthIdentifier() !== $user->getAuthIdentifier())) {
            $session = $this->app['session']->driver();
            $request = Request::create('/api/testing/impersonation');
            $request->setLaravelSession($session);

            if ($session->has('single_session_'.$guardName) && ($previous instanceof User || $previous instanceof Applicant)) {
                $this->app->make(SingleSessionService::class)->release($request, $guardName, $previous);
            }

            $session->forget(['single_session_'.$guardName, 'password_hash_'.$guardName]);
            $session->save();
        }

        $this->impersonatedAccounts[$guardName] = $user;

        return parent::actingAs($user, $guard);
    }

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
    }

    protected function user(string ...$permissions): User
    {
        $user = User::factory()->create([
            'uuid' => (string) Str::uuid(),
            'is_active' => true,
        ]);

        foreach ($permissions as $code) {
            $permission = Permission::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $code, 'is_active' => true],
            );

            $user->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function employee(?User $user = null, array $attributes = []): Employee
    {
        return Employee::query()->create(array_replace([
            'user_id' => $user?->id,
            'employee_number' => 'TEST-'.Str::uuid(),
            'first_name' => 'Request',
            'last_name' => 'Employee',
            'hire_date' => '2026-01-01',
            'employment_type' => 'regular',
            'employment_status' => 'active',
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @param  class-string<FormRequest>  $class
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $bindings
     * @return array<string, mixed>
     */
    protected function validateRequest(
        string $class,
        array $payload,
        ?User $user,
        array $bindings = [],
    ): array {
        [$parameters, $files] = $this->splitRequestData($payload);
        $request = $class::createFrom(Request::create('/phase7/form-request', 'POST', $parameters, [], $files));
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make(Redirector::class));
        $request->setUserResolver(static fn (): ?User => $user);

        $route = new Route('POST', '/phase7/form-request', static fn (): null => null);
        $route->bind($request);

        foreach ($bindings as $key => $value) {
            $route->setParameter($key, $value);
        }

        $request->setRouteResolver(static fn (): Route => $route);
        $request->validateResolved();

        return $request->validated();
    }

    /**
     * @param  class-string<FormRequest>  $class
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $bindings
     * @param  list<string>  $fields
     */
    protected function assertInvalidRequest(
        string $class,
        array $payload,
        ?User $user,
        array $bindings = [],
        array $fields = [],
    ): void {
        try {
            $this->validateRequest($class, $payload, $user, $bindings);
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());

            foreach ($fields as $field) {
                $this->assertArrayHasKey($field, $exception->errors());
            }

            return;
        }

        $this->fail('Expected the request input to fail validation.');
    }

    /**
     * @param  class-string<FormRequest>  $class
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $bindings
     */
    protected function assertUnauthorizedRequest(
        string $class,
        array $payload,
        ?User $user,
        array $bindings = [],
    ): void {
        try {
            $this->validateRequest($class, $payload, $user, $bindings);
        } catch (AuthorizationException $exception) {
            $this->assertInstanceOf(AuthorizationException::class, $exception);

            return;
        }

        $this->fail('Expected the request to reject this actor as unauthorized.');
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array{array<array-key, mixed>, array<array-key, mixed>}
     */
    private function splitRequestData(array $payload): array
    {
        $parameters = [];
        $files = [];

        foreach ($payload as $key => $value) {
            if ($value instanceof UploadedFile) {
                $files[$key] = $value;

                continue;
            }

            if (is_array($value)) {
                [$nestedParameters, $nestedFiles] = $this->splitRequestData($value);
                $parameters[$key] = $nestedParameters;

                if ($nestedFiles !== []) {
                    $files[$key] = $nestedFiles;
                }

                continue;
            }

            $parameters[$key] = $value;
        }

        return [$parameters, $files];
    }
}
