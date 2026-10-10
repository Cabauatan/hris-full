<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

class AuditLogService
{
    private const REDACTED = '[REDACTED]';

    private const SENSITIVE_KEY_FRAGMENTS = [
        'password',
        'token',
        'secret',
        'api_key',
        'authorization',
        'cookie',
        'private_key',
        'file_content',
        'document_content',
        'attachment_content',
    ];

    public function record(
        ?User $user,
        string $action,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $remarks = null,
        ?Request $request = null,
        ?string $requestId = null
    ): AuditLog {
        $action = strtolower(trim($action));

        if ($action === '') {
            throw new RuntimeException(
                'Audit action must not be empty.'
            );
        }

        if (mb_strlen($action) > 50) {
            throw new RuntimeException(
                'Audit action must not exceed 50 characters.'
            );
        }

        if ($user !== null && (! $user->exists || $user->getKey() === null)) {
            throw new RuntimeException(
                'Audit user must be a persisted user.'
            );
        }

        $auditableType = null;
        $auditableId = null;

        if ($auditable !== null) {
            if (! $auditable->exists || $auditable->getKey() === null) {
                throw new RuntimeException(
                    'Audit target must be a persisted model.'
                );
            }

            $auditableType = $auditable->getMorphClass();
            $auditableId = $auditable->getKey();

            if (
                ! is_int($auditableId)
                && ! (
                    is_string($auditableId)
                    && ctype_digit($auditableId)
                )
            ) {
                throw new RuntimeException(
                    'Audit target must use an integer primary key.'
                );
            }

            if (mb_strlen($auditableType) > 150) {
                throw new RuntimeException(
                    'Audit target type must not exceed 150 characters.'
                );
            }
        }

        if ($requestId !== null) {
            $requestId = trim($requestId);

            if (! Str::isUuid($requestId)) {
                throw new RuntimeException(
                    'Audit request ID must be a valid UUID.'
                );
            }
        }

        $requestMethod = $request?->method();
        $ipAddress = $request?->ip();

        if ($requestMethod !== null && mb_strlen($requestMethod) > 10) {
            throw new RuntimeException(
                'Audit request method must not exceed 10 characters.'
            );
        }

        if ($ipAddress !== null && mb_strlen($ipAddress) > 45) {
            throw new RuntimeException(
                'Audit IP address must not exceed 45 characters.'
            );
        }

        return AuditLog::query()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'old_values' => $this->sanitizeValues($oldValues),
            'new_values' => $this->sanitizeValues($newValues),
            'ip_address' => $ipAddress,
            'user_agent' => $request?->userAgent(),
            'request_method' => $requestMethod,
            // Deliberately excludes the query string to reduce accidental
            // logging of sensitive query parameters.
            'request_url' => $request?->url(),
            'request_id' => $requestId,
            'remarks' => $remarks,
        ]);
    }

    public function sanitizeValues(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $sanitized = [];

        foreach ($values as $key => $value) {
            if (
                is_string($key)
                && $this->isSensitiveKey($key)
            ) {
                $sanitized[$key] = self::REDACTED;

                continue;
            }

            $sanitized[$key] = is_array($value)
                ? $this->sanitizeValues($value)
                : $value;
        }

        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalizedKey = strtolower(preg_replace('/[^a-z0-9]/i', '', $key));

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalizedKey, str_replace('_', '', $fragment))) {
                return true;
            }
        }

        return false;
    }
}
