<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updatePassword', $this->user()) === true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['bail', 'required', 'string', 'max:255', 'current_password:web'],
            'password' => ['bail', 'required', 'string', 'confirmed', 'different:current_password', Password::min(8), 'max:255'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['current_password', 'password'] as $field) {
                if ($validator->errors()->has($field)) {
                    continue;
                }

                $password = $this->input($field);

                if (str_contains($password, "\0") || (config('hashing.driver', 'bcrypt') === 'bcrypt' && strlen($password) > 72)) {
                    $validator->errors()->add($field, 'The password must contain no null characters and must not exceed 72 bytes when using bcrypt.');
                }
            }
        }];
    }
}
