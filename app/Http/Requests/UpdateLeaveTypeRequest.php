<?php

namespace App\Http\Requests;

use App\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $leaveType = $this->route('leaveType');

        return $leaveType instanceof LeaveType
            && $this->user()?->can('update', $leaveType) === true;
    }

    public function rules(): array
    {
        $leaveType = $this->route('leaveType');

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('leave_types', 'code')->ignore($leaveType instanceof LeaveType ? $leaveType : null)],
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
