<?php

namespace App\Http\Requests;

use App\Models\Division;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDivisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $division = $this->route('division');

        return $division instanceof Division
            && $this->user()?->can('update', $division) === true;
    }

    public function rules(): array
    {
        $division = $this->route('division');

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('divisions', 'code')->ignore($division instanceof Division ? $division : null)],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
