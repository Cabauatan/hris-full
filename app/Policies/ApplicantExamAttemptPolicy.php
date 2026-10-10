<?php

namespace App\Policies;

use App\Models\Applicant;
use App\Models\ApplicantExamAttempt;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ApplicantExamAttemptPolicy
{
    public function view(User|Applicant $actor, ApplicantExamAttempt $applicantExamAttempt): bool|Response
    {
        if ($actor instanceof User) {
            return $actor->hasPermission('recruitment.view');
        }

        if ($applicantExamAttempt->application->applicant_id !== $actor->id) {
            return Response::denyAsNotFound();
        }

        return $actor->is_active;
    }

    public function submit(User|Applicant $actor, ApplicantExamAttempt $applicantExamAttempt): bool|Response
    {
        if (! $actor instanceof Applicant) {
            return false;
        }

        return $this->view($actor, $applicantExamAttempt);
    }

    public function grade(User|Applicant $actor, ApplicantExamAttempt $applicantExamAttempt): bool
    {
        return $actor instanceof User && $actor->hasPermission('recruitment.grade');
    }
}
