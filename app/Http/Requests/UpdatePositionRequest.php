<?php

namespace App\Http\Requests;

use App\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $position = $this->route('position');

        return $position instanceof Position
            && $this->user()?->can('update', $position) === true;
    }

    public function rules(): array
    {
        $position = $this->route('position');

        return [
            'department_id' => ['nullable', 'integer', 'numeric', 'exists:departments,id'],
            'code' => ['required', 'string', 'max:50', Rule::unique('positions', 'code')->ignore($position instanceof Position ? $position : null)],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
