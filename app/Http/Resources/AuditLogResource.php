<?php

namespace App\Http\Resources;

use App\Services\Audit\AuditLogService;
use Illuminate\Http\Request;

class AuditLogResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'request_method',
        'request_url',
        'request_id',
        'remarks',
        'created_at',
    ];

    public function toArray(Request $request): array
    {
        $sanitizer = app(AuditLogService::class);

        return array_replace(parent::toArray($request), [
            'old_values' => $sanitizer->sanitizeValues($this->resource->old_values),
            'new_values' => $sanitizer->sanitizeValues($this->resource->new_values),
            'request_url' => $this->requestUrl($this->resource->request_url),
        ]);
    }

    private function requestUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '');
    }
}
