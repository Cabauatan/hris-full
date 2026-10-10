<?php

namespace App\Policies;

use App\Models\Applicant;
use App\Models\RecruitmentExam;
use App\Models\User;

class RecruitmentExamPolicy
{
    public function viewAny(User|Applicant $actor): bool
    {
        return $actor instanceof User && $actor->hasPermission('recruitment.view');
    }

    public function view(User|Applicant $actor, RecruitmentExam $recruitmentExam): bool
    {
        return $actor instanceof User && $actor->hasPermission('recruitment.view');
    }

    public function create(User|Applicant $actor): bool
    {
        return $actor instanceof User && $actor->hasPermission('recruitment.manage');
    }
}
