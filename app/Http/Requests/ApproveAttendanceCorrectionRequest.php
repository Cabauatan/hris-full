<?php

namespace App\Http\Requests;

use App\Models\AttendanceCorrectionRequest;
use Illuminate\Foundation\Http\FormRequest;

class ApproveAttendanceCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $attendanceCorrectionRequest = $this->route(
            'attendanceCorrectionRequest'
        );

        return $attendanceCorrectionRequest instanceof AttendanceCorrectionRequest
            && $this->user()?->can(
                'approve',
                $attendanceCorrectionRequest
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
