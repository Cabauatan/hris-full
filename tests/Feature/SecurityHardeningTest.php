<?php

namespace Tests\Feature;

use App\Models\EmployeeDocument;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\FormRequestTestCase;

class SecurityHardeningTest extends FormRequestTestCase
{
    #[DataProvider('anonymousApiResponses')]
    public function test_anonymous_api_responses_prevent_storage_sniffing_and_framing(string $path, int $status): void
    {
        $response = $this->getJson($path)->assertStatus($status);
        $this->assertSecureResponse($response);
    }

    public function test_authenticated_identity_and_permission_errors_have_the_same_response_protection(): void
    {
        $actor = $this->user();
        $this->actingAs($actor);
        $this->assertSecureResponse($this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $actor->id));
        $this->assertSecureResponse($this->getJson('/api/v1/payroll-entries')->assertForbidden());
    }

    public function test_api_validation_and_server_errors_are_protected_without_exposing_error_details(): void
    {
        $this->assertSecureResponse($this->postJson('/api/v1/auth/login', [])->assertUnprocessable());
        config(['app.debug' => false]);
        Route::get('/api/v1/security-test-failure', static function (): never {
            throw new RuntimeException('Private infrastructure details.');
        });
        $response = $this->getJson('/api/v1/security-test-failure')->assertInternalServerError()
            ->assertExactJson(['message' => 'Server Error']);
        $this->assertSecureResponse($response);
    }

    public function test_private_document_download_keeps_attachment_bytes_and_disallows_cache_storage(): void
    {
        Storage::fake('local');
        $actor = $this->user('employees.manage-documents');
        Storage::disk('local')->put('employee-documents/phase15-private.txt', 'Private document bytes.');
        $document = EmployeeDocument::query()->create([
            'employee_id' => $this->employee()->id, 'document_type' => 'contract', 'title' => 'Private contract',
            'file_path' => 'employee-documents/phase15-private.txt', 'original_filename' => 'contract.txt',
            'mime_type' => 'text/plain', 'uploaded_by_user_id' => $actor->id,
        ]);
        $response = $this->actingAs($actor)->get('/api/v1/employee-documents/'.$document->id.'/download')
            ->assertOk()->assertDownload('contract.txt');
        $this->assertSame('Private document bytes.', $response->streamedContent());
        $this->assertSecureResponse($response);
        Storage::disk('local')->assertExists($document->file_path);
    }

    public function test_password_attempts_return_429_after_five_failures_and_the_limit_follows_the_account(): void
    {
        $this->freezeTime();
        $actor = $this->user();
        $hash = $actor->password;
        $payload = ['current_password' => 'incorrect', 'password' => 'new-password-15', 'password_confirmation' => 'new-password-15'];
        $this->actingAs($actor);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->putJson('/api/v1/auth/password', $payload)->assertUnprocessable()->assertJsonValidationErrors('current_password');
        }
        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.50'])->putJson('/api/v1/auth/password', $payload)
            ->assertTooManyRequests()->assertHeader('Retry-After', '60');
        $this->assertSecureResponse($response);
        $this->assertSame($hash, $actor->fresh()->password);
        $this->assertTrue(Hash::check('password', $actor->fresh()->password));
        $this->actingAs($this->user())->putJson('/api/v1/auth/password', $payload)->assertUnprocessable();
        $this->travel(61)->seconds();
        $this->actingAs($actor)->putJson('/api/v1/auth/password', $payload)->assertUnprocessable();
    }

    public function test_api_security_headers_do_not_disable_caching_on_unrelated_web_routes(): void
    {
        Route::get('/security-test-public', fn () => response('Public')->header('Cache-Control', 'public, max-age=600'));
        $this->get('/security-test-public')->assertOk()->assertHeader('Cache-Control', 'max-age=600, public');
    }

    public static function anonymousApiResponses(): array
    {
        return [
            'session bootstrap' => ['/api/v1/auth/csrf-cookie', 204],
            'authentication required' => ['/api/v1/payroll-entries', 401],
            'missing route' => ['/api/v1/phase15-missing', 404],
        ];
    }

    private function assertSecureResponse(TestResponse $response): void
    {
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control', ''));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control', ''));
        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Referrer-Policy', 'no-referrer');
    }
}
