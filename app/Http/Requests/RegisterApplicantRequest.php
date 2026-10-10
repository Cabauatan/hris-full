<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class RegisterApplicantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'mobile_number' => ['nullable', 'string', 'max:30'],
            'email' => ['bail', 'required', 'string', 'email', 'max:255', 'unique:applicants,email'],
            'password' => ['bail', 'required', 'string', 'min:8', 'max:255', 'confirmed'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('password')) {
                return;
            }

            $password = $this->input('password');

            if (str_contains($password, "\0") || (config('hashing.driver', 'bcrypt') === 'bcrypt' && strlen($password) > 72)) {
                $validator->errors()->add('password', 'The password must contain no null characters and must not exceed 72 bytes when using bcrypt.');
            }
        }];
    }
}
