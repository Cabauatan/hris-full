<?php

namespace App\Http\Requests;

use App\Models\AttendanceCorrectionRequest;
use Illuminate\Foundation\Http\FormRequest;

class RejectAttendanceCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $attendanceCorrectionRequest = $this->route(
            'attendanceCorrectionRequest'
        );

        return $attendanceCorrectionRequest instanceof AttendanceCorrectionRequest
            && $this->user()?->can(
                'reject',
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
