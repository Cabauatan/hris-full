<?php

namespace App\Http\Requests;

use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Department::class) === true;
    }

    public function rules(): array
    {
        return [
            'division_id' => ['nullable', 'integer', 'numeric', 'exists:divisions,id'],
            'code' => ['required', 'string', 'max:50', Rule::unique('departments', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
