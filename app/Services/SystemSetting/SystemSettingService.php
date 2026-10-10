<?php

namespace App\Services\SystemSetting;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SystemSettingService
{
    private const TYPES = [
        'string',
        'integer',
        'decimal',
        'boolean',
        'json',
    ];

    private function ensureValidType(string $type): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new RuntimeException(
                "Unsupported system setting type: {$type}."
            );
        }
    }

    private function encodeValue(string $type, mixed $value): ?string
    {
        $this->ensureValidType($type);

        if ($value === null) {
            return null;
        }

        return match ($type) {
            'string' => is_string($value)
                ? $value
                : throw new RuntimeException(
                    'String system setting value must be a string.'
                ),

            'integer' => match (true) {
                is_int($value) => (string) $value,
                is_string($value) && preg_match('/^-?\d+$/', $value) === 1 => $value,
                default => throw new RuntimeException(
                    'Integer system setting value must be an integer.'
                ),
            },

            'decimal' => match (true) {
                is_int($value) => (string) $value,
                is_string($value)
                    && preg_match('/^-?\d+(?:\.\d+)?$/', $value) === 1 => $value,
                default => throw new RuntimeException(
                    'Decimal system setting value must be an integer or decimal string.'
                ),
            },

            'boolean' => is_bool($value)
                ? ($value ? '1' : '0')
                : throw new RuntimeException(
                    'Boolean system setting value must be a boolean.'
                ),

            'json' => json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
        };
    }

    private function decodeValue(string $type, ?string $value): mixed
    {
        $this->ensureValidType($type);

        if ($value === null) {
            return null;
        }

        return match ($type) {
            'string' => $value,

            'integer' => preg_match('/^-?\d+$/', $value) === 1
                && filter_var($value, FILTER_VALIDATE_INT) !== false
                    ? (int) $value
                    : throw new RuntimeException(
                        'Stored integer system setting value is invalid.'
                    ),

            'decimal' => preg_match('/^-?\d+(?:\.\d+)?$/', $value) === 1
                ? $value
                : throw new RuntimeException(
                    'Stored decimal system setting value is invalid.'
                ),

            'boolean' => match ($value) {
                '1' => true,
                '0' => false,
                default => throw new RuntimeException(
                    'Stored boolean system setting value is invalid.'
                ),
            },

            'json' => json_decode(
                $value,
                true,
                512,
                JSON_THROW_ON_ERROR
            ),
        };
    }

    public function create(
        User $updatedBy,
        string $group,
        string $key,
        mixed $value,
        string $type = 'string',
        ?string $description = null,
        bool $isPublic = false,
        bool $isEditable = true
    ): SystemSetting {
        $this->ensureValidType($type);

        $group = trim($group);
        $key = trim($key);

        if ($group === '') {
            throw new RuntimeException('System setting group must not be empty.');
        }

        if ($key === '') {
            throw new RuntimeException('System setting key must not be empty.');
        }

        return DB::transaction(function () use (
            $updatedBy,
            $group,
            $key,
            $value,
            $type,
            $description,
            $isPublic,
            $isEditable
        ) {
            if (SystemSetting::query()->where('key', $key)->exists()) {
                throw new RuntimeException(
                    "System setting already exists: {$key}."
                );
            }

            $setting = SystemSetting::query()->create([
                'group' => $group,
                'key' => $key,
                'value' => $this->encodeValue($type, $value),
                'type' => $type,
                'description' => $description,
                'is_public' => $isPublic,
                'is_editable' => $isEditable,
                'updated_by_user_id' => $updatedBy->id,
            ]);

            return $setting->fresh(['updater']);
        });
    }

    public function valueOf(SystemSetting $setting): mixed
    {
        return $this->decodeValue($setting->type, $setting->value);
    }

    public function getValue(string $key): mixed
    {
        $key = trim($key);

        if ($key === '') {
            throw new RuntimeException(
                'System setting key must not be empty.'
            );
        }

        $setting = SystemSetting::query()
            ->where('key', $key)
            ->first();

        if ($setting === null) {
            throw new RuntimeException(
                "System setting not found: {$key}."
            );
        }

        return $this->decodeValue(
            $setting->type,
            $setting->value
        );
    }

    public function updateValue(
        SystemSetting $setting,
        User $updatedBy,
        mixed $value
    ): SystemSetting {
        return DB::transaction(function () use (
            $setting,
            $updatedBy,
            $value
        ) {
            $setting = SystemSetting::query()
                ->lockForUpdate()
                ->findOrFail($setting->id);

            if (! $setting->is_editable) {
                throw new RuntimeException(
                    "System setting is not editable: {$setting->key}."
                );
            }

            $setting->update([
                'value' => $this->encodeValue(
                    $setting->type,
                    $value
                ),
                'updated_by_user_id' => $updatedBy->id,
            ]);

            return $setting->fresh(['updater']);
        });
    }

    public function getPublicValues(?string $group = null): array
    {
        $query = SystemSetting::query()
            ->where('is_public', true);

        if ($group !== null) {
            $group = trim($group);

            if ($group === '') {
                throw new RuntimeException(
                    'System setting group must not be empty.'
                );
            }

            $query->where('group', $group);
        }

        return $query
            ->orderBy('key')
            ->get()
            ->mapWithKeys(fn (SystemSetting $setting) => [
                $setting->key => $this->decodeValue(
                    $setting->type,
                    $setting->value
                ),
            ])
            ->all();
    }
}
