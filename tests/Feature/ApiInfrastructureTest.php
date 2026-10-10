<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Middleware\HandleApiBusinessExceptions;
use App\Http\Resources\DivisionResource;
use App\Models\Division;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\FormRequestTestCase;

class ApiInfrastructureTest extends FormRequestTestCase
{
    public function test_api_route_names_are_unique_and_routes_compile_for_caching(): void
    {
        $this->getJson('/api/v1/divisions')->assertUnauthorized();
        $routes = $this->app['router']->getRoutes();
        $names = [];

        foreach ($routes as $route) {
            if (str_starts_with($route->uri(), 'api/v1/')) {
                $this->assertNotNull($route->getName());
                $names[] = $route->getName();
            }
        }

        $this->assertNotEmpty($names);
        $this->assertSame(count($names), count(array_unique($names)));
        $this->assertNotEmpty($routes->compile());
    }

    public function test_api_guest_without_accept_header_receives_json_401(): void
    {
        $this->get('/api/v1/divisions')->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_exact_domain_runtime_exception_is_rendered_as_json_conflict(): void
    {
        Route::get('/api/v1/test-domain-conflict', static function (): never {
            throw new RuntimeException('The request is no longer pending.');
        })->middleware(['api', HandleApiBusinessExceptions::class]);

        $this->get('/api/v1/test-domain-conflict')->assertConflict()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'The request is no longer pending.']);
    }

    public function test_database_runtime_subclass_remains_server_error_and_hides_database_details(): void
    {
        config(['app.debug' => false]);
        Route::get('/api/v1/test-database-error', static function (): never {
            DB::select('select * from phase8_missing_test_table');
            throw new RuntimeException('Unreachable.');
        })->middleware(['api', HandleApiBusinessExceptions::class]);

        $response = $this->get('/api/v1/test-database-error');

        $response->assertInternalServerError()->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'Server Error']);
        $this->assertStringNotContainsString('phase8_missing_test_table', $response->getContent());
    }

    public function test_model_not_found_runtime_subclass_remains_json_404(): void
    {
        Route::get('/api/v1/test-missing-model', static function (): never {
            throw (new ModelNotFoundException)->setModel(Division::class, [999999]);
        })->middleware(['api', HandleApiBusinessExceptions::class]);

        $this->get('/api/v1/test-missing-model')->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_business_exception_translation_is_not_global(): void
    {
        config(['app.debug' => false]);
        Route::get('/api/v1/test-unhandled-runtime', static function (): never {
            throw new RuntimeException('Unclassified infrastructure failure.');
        })->middleware('api');

        $this->get('/api/v1/test-unhandled-runtime')->assertInternalServerError()
            ->assertExactJson(['message' => 'Server Error']);
    }

    public function test_missing_bound_record_receives_json_404(): void
    {
        $this->actingAs($this->user('organization.view'));

        $this->get('/api/v1/divisions/999999')->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_pagination_is_bounded_and_returns_json_validation_without_accept_header(): void
    {
        $this->actingAs($this->user('organization.view'));

        $this->get('/api/v1/divisions?per_page=101')->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')->assertJsonValidationErrors('per_page');
        $this->get('/api/v1/divisions?page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    public function test_pagination_uses_descending_ids_and_preserves_navigation_query(): void
    {
        $ids = [];

        for ($number = 1; $number <= 16; $number++) {
            $ids[] = Division::query()->create(['code' => 'DIV-'.$number, 'name' => 'Division '.$number])->id;
        }

        $response = $this->actingAs($this->user('organization.view'))->getJson('/api/v1/divisions?per_page=5&page=3');

        $response->assertOk()->assertJsonPath('meta.current_page', 3)->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 16)->assertJsonPath('meta.last_page', 4);
        $this->assertSame(array_slice(array_reverse($ids), 10, 5), array_column($response->json('data'), 'id'));
        $this->assertStringContainsString('per_page=5', $response->json('links.next'));
    }

    public function test_resource_whitelist_omits_loaded_relations_and_unlisted_model_attributes(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(12, 0));
        $division = Division::query()->create([
            'code' => 'SAFE', 'name' => 'Safe division',
        ])->fresh();
        $division->setAttribute('unlisted_secret', 'Should never serialize.');
        $division->setRelation('creator', $this->user());
        Route::get('/api/v1/test-resource', static fn (): DivisionResource => new DivisionResource($division))->middleware('api');

        $response = $this->getJson('/api/v1/test-resource');

        $response->assertOk()->assertJsonPath('data.created_at', '2026-10-03T12:00:00+08:00');
        $this->assertSame(
            ['id', 'code', 'name', 'description', 'is_active', 'created_at', 'updated_at'],
            array_keys($response->json('data')),
        );
        $this->assertStringNotContainsString('Should never serialize.', $response->getContent());
    }

    public function test_employee_required_action_rejects_accounts_without_employee_profile(): void
    {
        Route::get('/api/v1/test-current-employee', static function (Request $request): JsonResponse {
            return (new class extends ApiController
            {
                public function requireEmployee(Request $request): JsonResponse
                {
                    return response()->json(['employee_id' => $this->currentEmployee($request)->id]);
                }
            })->requireEmployee($request);
        })->middleware(['api', 'auth', HandleApiBusinessExceptions::class]);

        $this->actingAs($this->user())->getJson('/api/v1/test-current-employee')->assertForbidden()
            ->assertJsonPath('message', 'An employee profile is required.');
    }
}
