<?php

namespace App\Http\Requests;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;

class CancelLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        $leaveRequest = $this->route('leaveRequest');

        return $leaveRequest instanceof LeaveRequest
            && $this->user()?->can(
                'cancel',
                $leaveRequest
            ) === true;
    }

    public function rules(): array
    {
        return [
            'reason' => [
                'nullable',
                'string',
            ],
        ];
    }
}
