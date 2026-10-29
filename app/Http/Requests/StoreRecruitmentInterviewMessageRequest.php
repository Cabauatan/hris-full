<?php

namespace App\Http\Requests;

use App\Models\Applicant;
use App\Models\RecruitmentInterview;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreRecruitmentInterviewMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $interview = $this->route('recruitmentInterview');
        $actor = $this->user();
        if (! $interview instanceof RecruitmentInterview || ! ($actor instanceof User || $actor instanceof Applicant)) {
            return false;
        }
        Gate::forUser($actor)->authorize('sendMessage', $interview);

        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
            'client_message_id' => ['required', 'uuid'],
        ];
    }
}
