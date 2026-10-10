<?php

namespace App\Http\Requests;

use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $department = $this->route('department');

        return $department instanceof Department
            && $this->user()?->can('update', $department) === true;
    }

    public function rules(): array
    {
        $department = $this->route('department');

        return [
            'division_id' => ['nullable', 'integer', 'numeric', 'exists:divisions,id'],
            'code' => ['required', 'string', 'max:50', Rule::unique('departments', 'code')->ignore($department instanceof Department ? $department : null)],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
