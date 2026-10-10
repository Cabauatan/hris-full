<?php

namespace App\Http\Requests;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;

class ApproveLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        $leaveRequest = $this->route('leaveRequest');

        return $leaveRequest instanceof LeaveRequest
            && $this->user()?->can(
                'approve',
                $leaveRequest
            ) === true;
    }

    public function rules(): array
    {
        $leaveRequest = $this->route('leaveRequest');

        return [
            'approved_units' => [
                'required',
                'numeric',
                'gt:0',
                'max:999999.99',
                'regex:/^\d+(?:\.\d{1,2})?$/',
                'max:'.($leaveRequest instanceof LeaveRequest ? $leaveRequest->requested_units : 0),
            ],

            'remarks' => [
                'nullable',
                'string',
            ],
        ];
    }
}
