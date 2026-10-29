<?php

namespace App\Http\Requests;

use App\Models\Applicant;
use App\Models\RecruitmentInterview;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class IndexRecruitmentInterviewMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $interview = $this->route('recruitmentInterview');
        $actor = $this->user();
        if (! $interview instanceof RecruitmentInterview || ! ($actor instanceof User || $actor instanceof Applicant)) {
            return false;
        }
        Gate::forUser($actor)->authorize('readMessages', $interview);

        return true;
    }

    public function rules(): array
    {
        return [
            'after_id' => ['sometimes', 'integer', 'min:0', 'max:'.PHP_INT_MAX, 'prohibits:before_id'],
            'before_id' => ['sometimes', 'integer', 'min:1', 'max:'.PHP_INT_MAX, 'prohibits:after_id'],
        ];
    }
}
