<?php

namespace App\Http\Requests;

use App\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Position::class) === true;
    }

    public function rules(): array
    {
        return [
            'department_id' => ['nullable', 'integer', 'numeric', 'exists:departments,id'],
            'code' => ['required', 'string', 'max:50', Rule::unique('positions', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
