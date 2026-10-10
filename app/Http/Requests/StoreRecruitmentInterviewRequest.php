<?php

namespace App\Http\Requests;

use App\Models\JobApplication;
use App\Models\RecruitmentInterview;
use App\Models\User;
use App\Services\Recruitment\RecruitmentInterviewService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class StoreRecruitmentInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $application = $this->route('jobApplication');
        $actor = $this->user();
        if (! $application instanceof JobApplication || ! $actor instanceof User) {
            return false;
        }
        Gate::forUser($actor)->authorize('schedule', [RecruitmentInterview::class, $application]);

        return true;
    }

    public function rules(): array
    {
        return [
            'round' => ['required', 'string', Rule::in(['initial', 'final'])],
            'scheduled_at' => ['required', 'string', 'max:35'],
            'duration_minutes' => ['required', 'integer', 'between:15,120'],
            'interviewer_ids' => ['required', 'array', 'list', 'between:1,4'],
            'interviewer_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'private_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            try {
                $schedule = app(RecruitmentInterviewService::class)->parseSchedule($this->input('scheduled_at'));
                if ($schedule->isPast()) {
                    $validator->errors()->add('scheduled_at', 'The interview must be scheduled in the future.');
                }
            } catch (ValidationException $exception) {
                $validator->errors()->add('scheduled_at', $exception->errors()['scheduled_at'][0]);
            }
            $interviewers = User::query()->whereIn('id', $this->input('interviewer_ids'))->get();
            if ($interviewers->contains(fn (User $user): bool => ! $user->hasPermission('recruitment.manage'))) {
                $validator->errors()->add('interviewer_ids', 'Every interviewer must be an active recruitment manager.');
            }
        }];
    }
}
