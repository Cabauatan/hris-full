<?php

namespace App\Policies;

use App\Models\Applicant;
use App\Models\JobApplication;
use App\Models\RecruitmentInterview;
use App\Models\User;
use App\Services\Recruitment\RecruitmentInterviewService;
use Illuminate\Auth\Access\Response;

class RecruitmentInterviewPolicy
{
    public function __construct(private RecruitmentInterviewService $interviews) {}

    public function viewAny(User|Applicant $actor, JobApplication $application): bool|Response
    {
        if ($actor instanceof User) {
            return $actor->hasPermission('recruitment.view');
        }

        return $application->applicant_id !== $actor->id ? Response::denyAsNotFound() : $actor->is_active;
    }

    public function schedule(User|Applicant $actor, JobApplication $application): bool
    {
        return $actor instanceof User && $actor->hasPermission('recruitment.manage');
    }

    public function view(User|Applicant $actor, RecruitmentInterview $interview): bool|Response
    {
        return $this->viewAny($actor, $interview->application);
    }

    public function participate(User|Applicant $actor, RecruitmentInterview $interview): bool|Response
    {
        if ($actor instanceof Applicant && $interview->application->applicant_id !== $actor->id) {
            return Response::denyAsNotFound();
        }

        return $this->interviews->isParticipant($interview, $actor);
    }

    public function join(User|Applicant $actor, RecruitmentInterview $interview): bool
    {
        return $this->interviews->canJoin($interview, $actor);
    }

    public function readMessages(User|Applicant $actor, RecruitmentInterview $interview): bool|Response
    {
        return $this->participate($actor, $interview);
    }

    public function sendMessage(User|Applicant $actor, RecruitmentInterview $interview): bool|Response
    {
        return $this->participate($actor, $interview);
    }

    public function cancel(User|Applicant $actor, RecruitmentInterview $interview): bool
    {
        return $actor instanceof User && $actor->hasPermission('recruitment.manage');
    }

    public function complete(User|Applicant $actor, RecruitmentInterview $interview): bool
    {
        return $this->cancel($actor, $interview);
    }
}
