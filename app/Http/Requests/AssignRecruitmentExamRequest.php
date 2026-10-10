<?php

namespace App\Http\Requests;

use App\Models\JobApplication;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class AssignRecruitmentExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        $application = $this->route('jobApplication');

        return $application instanceof JobApplication && $this->user() instanceof User
            && $this->user()->can('assignExam', $application);
    }

    public function rules(): array
    {
        return ['recruitment_exam_id' => ['required', 'integer', 'min:1', 'exists:recruitment_exams,id']];
    }
}
