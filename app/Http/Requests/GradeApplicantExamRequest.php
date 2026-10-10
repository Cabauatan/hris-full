<?php

namespace App\Http\Requests;

use App\Models\ApplicantExamAttempt;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class GradeApplicantExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        $attempt = $this->route('applicantExamAttempt');

        return $attempt instanceof ApplicantExamAttempt && $this->user() instanceof User
            && $this->user()->can('grade', $attempt);
    }

    public function rules(): array
    {
        return [
            'grades' => ['required', 'array', 'list', 'between:1,50'],
            'grades.*' => ['required', 'array:question_id,score,feedback'],
            'grades.*.question_id' => ['required', 'string', 'uuid'],
            'grades.*.score' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'grades.*.feedback' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
