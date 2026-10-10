<?php

namespace App\Policies;

use App\Models\Applicant;
use App\Models\User;

class TelegramConnectionPolicy
{
    public function manage(User|Applicant $actor, User|Applicant $account): bool
    {
        return $actor->exists && $account->exists
            && $actor->is_active && $account->is_active
            && $actor::class === $account::class
            && $actor->getKey() === $account->getKey();
    }
}
