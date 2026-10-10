<?php

namespace App\Http\Requests;

use App\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', LeaveType::class) === true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('leave_types', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'is_paid' => ['sometimes', 'boolean'],
            'requires_balance' => ['sometimes', 'boolean'],
            'allows_partial_day' => ['sometimes', 'boolean'],
            'requires_document' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
