<?php

namespace App\Http\Requests;

use App\Models\AttendanceCorrectionRequest;
use Illuminate\Foundation\Http\FormRequest;

class CancelAttendanceCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $attendanceCorrectionRequest = $this->route(
            'attendanceCorrectionRequest'
        );

        return $attendanceCorrectionRequest instanceof AttendanceCorrectionRequest
            && $this->user()?->can(
                'cancel',
                $attendanceCorrectionRequest
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
