<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Services\SystemSetting\SystemSettingService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class SystemSettingResourceTest extends FormRequestTestCase
{
    #[DataProvider('integerValues')]
    public function test_detail_preserves_integer_precision_without_changing_safe_number_types(?string $storedValue, int|string|null $expected): void
    {
        $setting = SystemSetting::query()->create([
            'group' => 'precision', 'key' => 'precision.integer', 'type' => 'integer', 'value' => $storedValue,
        ]);

        $response = $this->actingAs($this->user('settings.view'))
            ->getJson('/api/v1/system-settings/'.$setting->id);

        $response->assertOk()->assertJsonPath('data.type', 'integer')->assertJsonPath('data.value', $expected);
    }

    public function test_collection_preserves_large_positive_and_negative_integer_values(): void
    {
        $positive = SystemSetting::query()->create([
            'group' => 'precision', 'key' => 'precision.positive', 'type' => 'integer', 'value' => '9007199254740993',
        ]);
        $negative = SystemSetting::query()->create([
            'group' => 'precision', 'key' => 'precision.negative', 'type' => 'integer', 'value' => '-9007199254740993',
        ]);

        $response = $this->actingAs($this->user('settings.view'))->getJson('/api/v1/system-settings');

        $response->assertOk()->assertJsonCount(2, 'data');
        $values = array_column($response->json('data'), 'value', 'id');
        $this->assertSame('9007199254740993', $values[$positive->id]);
        $this->assertSame('-9007199254740993', $values[$negative->id]);
    }

    #[DataProvider('largeIntegerValues')]
    public function test_create_preserves_large_integer_response_and_storage(string $value): void
    {
        $actor = $this->user('settings.manage');

        $response = $this->actingAs($actor)->postJson('/api/v1/system-settings', [
            'group' => 'precision', 'key' => 'precision.created', 'type' => 'integer', 'value' => $value,
        ]);

        $response->assertCreated()->assertJsonPath('data.value', $value)->assertJsonPath('data.type', 'integer');
        $this->assertDatabaseHas('system_settings', [
            'id' => $response->json('data.id'), 'value' => $value, 'type' => 'integer', 'updated_by_user_id' => $actor->id,
        ]);
    }

    #[DataProvider('largeIntegerValues')]
    public function test_update_preserves_large_integer_response_and_storage(string $value): void
    {
        $actor = $this->user('settings.manage');
        $setting = SystemSetting::query()->create([
            'group' => 'precision', 'key' => 'precision.updated', 'type' => 'integer', 'value' => '0',
        ]);

        $response = $this->actingAs($actor)->putJson('/api/v1/system-settings/'.$setting->id, ['value' => $value]);

        $response->assertOk()->assertJsonPath('data.value', $value)->assertJsonPath('data.type', 'integer');
        $this->assertDatabaseHas('system_settings', [
            'id' => $setting->id, 'value' => $value, 'type' => 'integer', 'updated_by_user_id' => $actor->id,
        ]);
    }

    #[DataProvider('otherTypedValues')]
    public function test_other_setting_types_keep_their_existing_json_values(string $type, string $storedValue, mixed $expected): void
    {
        $setting = SystemSetting::query()->create([
            'group' => 'precision', 'key' => 'precision.typed', 'type' => $type, 'value' => $storedValue,
        ]);

        $response = $this->actingAs($this->user('settings.view'))->getJson('/api/v1/system-settings/'.$setting->id);

        $response->assertOk()->assertJsonPath('data.type', $type)->assertJsonPath('data.value', $expected);
    }

    public function test_integer_setting_business_value_remains_an_integer(): void
    {
        $setting = SystemSetting::query()->create([
            'group' => 'precision', 'key' => 'precision.service', 'type' => 'integer', 'value' => '9007199254740993',
        ]);

        $value = app(SystemSettingService::class)->valueOf($setting);

        $this->assertSame(9_007_199_254_740_993, $value);
    }

    public static function integerValues(): array
    {
        return [
            'zero' => ['0', 0],
            'positive number' => ['42', 42],
            'negative number' => ['-42', -42],
            'largest safe number' => ['9007199254740991', 9_007_199_254_740_991],
            'smallest safe number' => ['-9007199254740991', -9_007_199_254_740_991],
            'first positive outside safe range' => ['9007199254740992', '9007199254740992'],
            'first negative outside safe range' => ['-9007199254740992', '-9007199254740992'],
            'positive loses precision in JavaScript' => ['9007199254740993', '9007199254740993'],
            'negative loses precision in JavaScript' => ['-9007199254740993', '-9007199254740993'],
            'largest PHP integer' => [(string) PHP_INT_MAX, (string) PHP_INT_MAX],
            'smallest PHP integer' => [(string) PHP_INT_MIN, (string) PHP_INT_MIN],
            'null' => [null, null],
        ];
    }

    public static function largeIntegerValues(): array
    {
        return [
            'positive loses precision in JavaScript' => ['9007199254740993'],
            'negative loses precision in JavaScript' => ['-9007199254740993'],
            'largest PHP integer' => [(string) PHP_INT_MAX],
            'smallest PHP integer' => [(string) PHP_INT_MIN],
        ];
    }

    public static function otherTypedValues(): array
    {
        return [
            'true boolean' => ['boolean', '1', true],
            'false boolean' => ['boolean', '0', false],
            'JSON array' => ['json', '[1,false,"9007199254740993"]', [1, false, '9007199254740993']],
            'JSON object' => ['json', '{"enabled":true,"limit":42}', ['enabled' => true, 'limit' => 42]],
            'decimal string' => ['decimal', '9007199254740993.25', '9007199254740993.25'],
            'empty string' => ['string', '', ''],
        ];
    }
}
