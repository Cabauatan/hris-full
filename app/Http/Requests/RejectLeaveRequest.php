<?php

namespace App\Http\Requests;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;

class RejectLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        $leaveRequest = $this->route('leaveRequest');

        return $leaveRequest instanceof LeaveRequest
            && $this->user()?->can(
                'reject',
                $leaveRequest
            ) === true;
    }

    public function rules(): array
    {
        return [
            'remarks' => [
                'nullable',
                'string',
            ],
        ];
    }
}
