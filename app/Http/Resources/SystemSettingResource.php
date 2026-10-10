<?php

namespace App\Http\Resources;

use App\Services\SystemSetting\SystemSettingService;
use Illuminate\Http\Request;

class SystemSettingResource extends ApiResource
{
    private const JAVASCRIPT_MAX_SAFE_INTEGER = 9_007_199_254_740_991;

    protected const FIELDS = [
        'id',
        'group',
        'key',
        'value',
        'type',
        'description',
        'is_public',
        'is_editable',
        'updated_by_user_id',
        'created_at',
        'updated_at',
    ];

    public function toArray(Request $request): array
    {
        $value = app(SystemSettingService::class)->valueOf($this->resource);

        if ($this->resource->type === 'integer' && is_int($value)
            && ($value > self::JAVASCRIPT_MAX_SAFE_INTEGER || $value < -self::JAVASCRIPT_MAX_SAFE_INTEGER)) {
            $value = (string) $value;
        }

        return array_replace(parent::toArray($request), [
            'value' => $value,
        ]);
    }
}
