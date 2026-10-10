<?php

namespace App\Http\Requests;

use App\Models\RecruitmentInterview;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class CancelRecruitmentInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $interview = $this->route('recruitmentInterview');
        $actor = $this->user();
        if (! $interview instanceof RecruitmentInterview || ! $actor instanceof User) {
            return false;
        }
        Gate::forUser($actor)->authorize('cancel', $interview);

        return true;
    }

    public function rules(): array
    {
        return ['private_notes' => ['nullable', 'string', 'max:5000']];
    }
}
