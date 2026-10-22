<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexUserLookupRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->is_active) {
            return false;
        }

        return match ($this->input('purpose')) {
            'employee-link' => $user->hasPermission('employees.create') || $user->hasPermission('employees.update'),
            'delegate', 'delegator' => $user->hasPermission('approval-delegations.manage'),
            default => $user->hasPermission('employees.create') || $user->hasPermission('employees.update')
                || $user->hasPermission('approval-delegations.manage'),
        };
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'purpose' => ['required', 'string', Rule::in(['employee-link', 'delegate', 'delegator'])],
            'per_page' => ['sometimes', 'integer', 'numeric', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'numeric', 'min:1'],
        ];
    }
}
