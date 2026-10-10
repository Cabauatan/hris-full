<?php

namespace App\Policies;

use App\Models\Applicant;
use App\Models\JobOpening;
use App\Models\User;

class JobOpeningPolicy
{
    public function viewAny(User|Applicant $actor): bool
    {
        return $actor instanceof User && $actor->hasPermission('recruitment.view');
    }

    public function view(User|Applicant $actor, JobOpening $jobOpening): bool
    {
        return $actor instanceof User && $actor->hasPermission('recruitment.view');
    }

    public function create(User|Applicant $actor): bool
    {
        return $actor instanceof User && $actor->hasPermission('recruitment.manage');
    }

    public function update(User|Applicant $actor, JobOpening $jobOpening): bool
    {
        return $actor instanceof User && $actor->hasPermission('recruitment.manage');
    }
}
