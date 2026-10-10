<?php

namespace App\Http\Requests;

use App\Models\ApprovalDelegation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApprovalDelegationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ApprovalDelegation::class) === true;
    }

    public function rules(): array
    {
        return [
            'delegator_user_id' => ['required', 'integer', 'numeric', 'exists:users,id', $this->user()?->hasPermission('employees.view') ? Rule::exists('users', 'id') : Rule::in([$this->user()?->id])],
            'delegate_user_id' => ['required', 'integer', 'numeric', 'exists:users,id', 'different:delegator_user_id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', Rule::when(is_string($this->input('starts_at')), 'after_or_equal:starts_at')],
            'reason' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
