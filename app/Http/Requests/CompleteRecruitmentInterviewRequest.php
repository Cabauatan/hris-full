<?php

namespace App\Http\Requests;

use App\Models\RecruitmentInterview;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class CompleteRecruitmentInterviewRequest extends CancelRecruitmentInterviewRequest
{
    public function authorize(): bool
    {
        $interview = $this->route('recruitmentInterview');
        $actor = $this->user();
        if (! $interview instanceof RecruitmentInterview || ! $actor instanceof User) {
            return false;
        }
        Gate::forUser($actor)->authorize('complete', $interview);

        return true;
    }
}
