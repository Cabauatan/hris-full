<?php

namespace App\Http\Requests;

use App\Models\Applicant;
use App\Models\RecruitmentInterview;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class JoinRecruitmentInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $interview = $this->route('recruitmentInterview');
        $actor = $this->user();
        if (! $interview instanceof RecruitmentInterview || ! ($actor instanceof User || $actor instanceof Applicant)) {
            return false;
        }
        Gate::forUser($actor)->authorize('participate', $interview);

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
