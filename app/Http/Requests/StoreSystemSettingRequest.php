<?php

namespace App\Http\Requests;

use App\Models\SystemSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSystemSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(
            'create',
            SystemSetting::class
        ) === true;
    }

    public function rules(): array
    {
        return [
            'group' => [
                'required',
                'string',
                'max:100',
            ],

            'key' => [
                'required',
                'string',
                'max:150',
                Rule::unique('system_settings', 'key'),
            ],

            'value' => [
                'present',
                'nullable',
            ],

            'type' => [
                'required',
                'string',
                Rule::in([
                    'string',
                    'integer',
                    'decimal',
                    'boolean',
                    'json',
                ]),
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'is_public' => [
                'sometimes',
                'boolean',
            ],

            'is_editable' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (
                    $validator->errors()->has('type')
                    || $validator->errors()->has('value')
                ) {
                    return;
                }

                $value = $this->input('value');

                if ($value === null) {
                    return;
                }

                match ($this->input('type')) {
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

                    default => null,
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
                'The value must be a JSON-compatible object or array.'
            );
        }
    }
}
