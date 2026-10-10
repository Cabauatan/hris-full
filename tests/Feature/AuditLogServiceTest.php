<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\FormRequestTestCase;

class AuditLogServiceTest extends FormRequestTestCase
{
    public function test_records_normalized_action_morph_identity_correlation_and_request_without_query_secrets(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', config('app.timezone')));
        $user = $this->user();
        $employee = $this->employee($user);
        $request = Request::create('https://hris.example.test/api/v1/employees/1?token=QUERY-SECRET', 'PATCH', [], [], [], [
            'REMOTE_ADDR' => '2001:db8::1', 'HTTP_USER_AGENT' => 'Phase14 fixture browser',
        ]);

        $audit = app(AuditLogService::class)->record($user, '  UPDATED  ', $employee,
            ['first_name' => 'Before'], ['first_name' => 'After'], 'Correction', $request,
            '  123e4567-e89b-42d3-a456-426614174000  ');

        $this->assertDatabaseHas('audit_logs', [
            'id' => $audit->id, 'user_id' => $user->id, 'action' => 'updated',
            'auditable_type' => 'employee', 'auditable_id' => $employee->id,
            'request_method' => 'PATCH', 'request_url' => 'https://hris.example.test/api/v1/employees/1',
            'ip_address' => '2001:db8::1', 'user_agent' => 'Phase14 fixture browser',
            'request_id' => '123e4567-e89b-42d3-a456-426614174000', 'remarks' => 'Correction',
        ]);
        $this->assertSame(['first_name' => 'Before'], $audit->fresh()->old_values);
        $this->assertSame(['first_name' => 'After'], $audit->fresh()->new_values);
        $this->assertSame('2026-10-05 09:00:00', $audit->created_at->format('Y-m-d H:i:s'));
        $this->assertTrue($audit->auditable->is($employee));
        $this->assertStringNotContainsString('QUERY-SECRET', $audit->toJson());
    }

    public function test_persists_recursive_redaction_across_arrays_and_preserves_safe_value_types(): void
    {
        $audit = app(AuditLogService::class)->record(null, 'imported', null,
            ['nested' => [['PaSs-WoRd' => 'SECRET-OLD', 'enabled' => false]]],
            ['items' => [
                ['apiKey' => 'SECRET-API', 'status' => 'approved', 'minutes' => 30],
                ['AUTHORIZATION' => 'SECRET-AUTH', 'private.key' => 'SECRET-KEY', 'label' => 'José'],
            ], 'attachment_content' => ['bytes' => 'SECRET-FILE'], 'balance' => '0.01', 'optional' => null]);

        $audit->refresh();
        $this->assertSame(['nested' => [['PaSs-WoRd' => '[REDACTED]', 'enabled' => false]]], $audit->old_values);
        $this->assertSame(['items' => [
            ['apiKey' => '[REDACTED]', 'status' => 'approved', 'minutes' => 30],
            ['AUTHORIZATION' => '[REDACTED]', 'private.key' => '[REDACTED]', 'label' => 'José'],
        ], 'attachment_content' => '[REDACTED]', 'balance' => '0.01', 'optional' => null], $audit->new_values);
        $this->assertStringNotContainsString('SECRET-', DB::table('audit_logs')->where('id', $audit->id)->first()->new_values);
        $this->assertStringNotContainsString('SECRET-', DB::table('audit_logs')->where('id', $audit->id)->first()->old_values);
    }

    #[DataProvider('sensitiveKeys')]
    public function test_redacts_normalized_credential_and_document_keys(string $key): void
    {
        $result = app(AuditLogService::class)->sanitizeValues([$key => ['secret' => 'fixture'], 'safe' => [0, false, null, 'visible']]);

        $this->assertSame([$key => '[REDACTED]', 'safe' => [0, false, null, 'visible']], $result);
    }

    public function test_system_audit_preserves_null_snapshots_and_optional_context(): void
    {
        $audit = app(AuditLogService::class)->record(null, 'system_job');

        $this->assertModelExists($audit);
        foreach (['user_id', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'ip_address', 'user_agent', 'request_method', 'request_url', 'request_id', 'remarks'] as $field) {
            $this->assertNull($audit->getAttribute($field), $field);
        }
    }

    #[DataProvider('invalidActions')]
    public function test_rejects_invalid_action_without_an_audit_record(string $action, string $message): void
    {
        try {
            app(AuditLogService::class)->record(null, $action);
            $this->fail('Expected the invalid action to be refused.');
        } catch (RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_accepts_fifty_multibyte_characters_without_using_byte_length(): void
    {
        $audit = app(AuditLogService::class)->record(null, str_repeat('ñ', 50));

        $this->assertSame(str_repeat('ñ', 50), $audit->fresh()->action);
    }

    public function test_refuses_unpersisted_actor_without_saving_an_audit(): void
    {
        try {
            app(AuditLogService::class)->record(User::factory()->make(), 'created');
            $this->fail('Expected an unpersisted actor to be refused.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit user must be a persisted user.', $exception->getMessage());
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_refuses_unpersisted_target_without_saving_an_audit(): void
    {
        try {
            app(AuditLogService::class)->record(null, 'created', new Employee);
            $this->fail('Expected an unpersisted target to be refused.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit target must be a persisted model.', $exception->getMessage());
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_refuses_invalid_correlation_identifier_without_saving_an_audit(): void
    {
        try {
            app(AuditLogService::class)->record(null, 'updated', requestId: 'not-a-uuid');
            $this->fail('Expected an invalid request identifier to be refused.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit request ID must be a valid UUID.', $exception->getMessage());
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_rejects_changes_to_an_existing_audit_and_preserves_original_values(): void
    {
        $audit = app(AuditLogService::class)->record(null, 'created', newValues: ['status' => 'pending']);

        try {
            $audit->update(['action' => 'changed', 'new_values' => ['status' => 'approved']]);
            $this->fail('Expected an audit update to be refused.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit logs are immutable and cannot be updated.', $exception->getMessage());
        }

        $this->assertSame('created', $audit->fresh()->action);
        $this->assertSame(['status' => 'pending'], $audit->fresh()->new_values);
    }

    public function test_rejects_deleting_an_audit_and_retains_the_record(): void
    {
        $audit = app(AuditLogService::class)->record(null, 'created');

        try {
            $audit->delete();
            $this->fail('Expected an audit deletion to be refused.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit logs are immutable and cannot be deleted.', $exception->getMessage());
        }

        $this->assertModelExists($audit);
    }

    public function test_rolled_back_business_transaction_leaves_no_orphan_audit(): void
    {
        try {
            DB::transaction(function (): never {
                app(AuditLogService::class)->record(null, 'created', newValues: ['status' => 'pending']);
                throw new RuntimeException('Fixture business failure.');
            });
            $this->fail('Expected the transaction to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fixture business failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function sensitiveKeys(): array
    {
        return [
            'password hash' => ['passwordHash'], 'refresh token' => ['refresh-token'],
            'secret' => ['CLIENT_SECRET'], 'api key' => ['api.key'],
            'authorization' => ['Authorization'], 'cookie' => ['session_cookie'],
            'private key' => ['privateKey'], 'file bytes' => ['FILE_CONTENT'],
            'document bytes' => ['document-content'], 'attachment bytes' => ['attachmentContent'],
        ];
    }

    public static function invalidActions(): array
    {
        return [
            'empty' => ['', 'Audit action must not be empty.'],
            'whitespace' => [" \t\r\n ", 'Audit action must not be empty.'],
            'too long' => [str_repeat('a', 51), 'Audit action must not exceed 50 characters.'],
            'too long multibyte' => [str_repeat('ñ', 51), 'Audit action must not exceed 50 characters.'],
        ];
    }
}
