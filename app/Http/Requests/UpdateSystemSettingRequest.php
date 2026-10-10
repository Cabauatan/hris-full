<?php

namespace App\Http\Requests;

use App\Models\SystemSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSystemSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $setting = $this->route('systemSetting');

        return $setting instanceof SystemSetting
            && $this->user()?->can('update', $setting) === true;
    }

    public function rules(): array
    {
        return [
            'value' => [
                'present',
                'nullable',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('value')) {
                    return;
                }

                $setting = $this->route('systemSetting');

                if (! $setting instanceof SystemSetting) {
                    return;
                }

                $value = $this->input('value');

                if ($value === null) {
                    return;
                }

                match ($setting->type) {
                    'string' => $this->validateStringValue(
                        $validator,
                        $value
                    ),

                    'integer' => $this->validateIntegerValue(
                        $validator,
                        $value
                    ),

                    'decimal' => $this->validateDecimalValue(
                        $validator,
                        $value
                    ),

                    'boolean' => $this->validateBooleanValue(
                        $validator,
                        $value
                    ),

                    'json' => $this->validateJsonValue(
                        $validator,
                        $value
                    ),

                    default => $validator->errors()->add(
                        'value',
                        'The system setting type is not supported.'
                    ),
                };
            },
        ];
    }

    private function validateStringValue(
        Validator $validator,
        mixed $value
    ): void {
        if (! is_string($value)) {
            $validator->errors()->add(
                'value',
                'The value must be a string.'
            );
        }
    }

    private function validateIntegerValue(
        Validator $validator,
        mixed $value
    ): void {
        if (
            ! is_int($value)
            && ! (
                is_string($value)
                && preg_match('/^-?\d+$/', $value) === 1
                && filter_var($value, FILTER_VALIDATE_INT) !== false
            )
        ) {
            $validator->errors()->add(
                'value',
                'The value must be an integer.'
            );
        }
    }

    private function validateDecimalValue(
        Validator $validator,
        mixed $value
    ): void {
        if (
            ! is_int($value)
            && ! (
                is_string($value)
                && preg_match('/^-?\d+(?:\.\d+)?$/', $value) === 1
            )
        ) {
            $validator->errors()->add(
                'value',
                'The value must be an integer or decimal string.'
            );
        }
    }

    private function validateBooleanValue(
        Validator $validator,
        mixed $value
    ): void {
        if (! is_bool($value)) {
            $validator->errors()->add(
                'value',
                'The value must be a boolean.'
            );
        }
    }

    private function validateJsonValue(
        Validator $validator,
        mixed $value
    ): void {
        if (
            ! is_array($value)
            && ! is_object($value)
        ) {
            $validator->errors()->add(
                'value',
                'The value must be a valid JSON-compatible object or array.'
            );
        }
    }
}
