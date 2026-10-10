<?php

namespace App\Http\Requests;

use App\Models\Applicant;
use App\Models\ApplicantExamAttempt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SubmitApplicantExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        $attempt = $this->route('applicantExamAttempt');
        $actor = $this->user();

        if (! $attempt instanceof ApplicantExamAttempt || ! $actor instanceof Applicant) {
            return false;
        }

        Gate::forUser($actor)->authorize('submit', $attempt);

        return true;
    }

    public function rules(): array
    {
        return [
            'answers' => ['required', 'array', 'list', 'between:1,50'],
            'answers.*' => ['required', 'array:question_id,answer'],
            'answers.*.question_id' => ['required', 'string', 'uuid'],
            'answers.*.answer' => ['required', 'string', 'max:10000'],
        ];
    }
}
