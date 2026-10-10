<?php

namespace App\Policies;

use App\Models\Applicant;
use App\Models\JobApplication;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class JobApplicationPolicy
{
    public function viewAny(User|Applicant $actor): bool
    {
        return $actor instanceof Applicant ? $actor->is_active : $actor->hasPermission('recruitment.view');
    }

    public function create(User|Applicant $actor): bool
    {
        return $actor instanceof Applicant && $actor->is_active;
    }

    public function view(User|Applicant $actor, JobApplication $jobApplication): bool|Response
    {
        if ($actor instanceof User) {
            return $actor->hasPermission('recruitment.view');
        }

        if ($jobApplication->applicant_id !== $actor->id) {
            return Response::denyAsNotFound();
        }

        return $actor->is_active;
    }

    public function approve(User|Applicant $actor, JobApplication $jobApplication): bool
    {
        return $actor instanceof User && $actor->hasPermission('recruitment.manage');
    }

    public function reject(User|Applicant $actor, JobApplication $jobApplication): bool
    {
        return $actor instanceof User && $actor->hasPermission('recruitment.manage');
    }

    public function assignExam(User|Applicant $actor, JobApplication $jobApplication): bool
    {
        return $actor instanceof User && $actor->hasPermission('recruitment.manage');
    }

    public function hire(User|Applicant $actor, JobApplication $jobApplication): bool
    {
        return $actor instanceof User && $actor->hasPermission('recruitment.manage');
    }
}
