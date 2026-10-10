<?php

namespace App\Policies;

use App\Models\CompanyProfile;
use App\Models\User;

class CompanyProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('organization.view');
    }

    public function view(User $user, CompanyProfile $companyProfile): bool
    {
        return $user->hasPermission('organization.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CompanyProfile $companyProfile): bool
    {
        return $user->hasPermission('organization.manage');
    }

    public function delete(User $user, CompanyProfile $companyProfile): bool
    {
        return false;
    }

    public function restore(User $user, CompanyProfile $companyProfile): bool
    {
        return false;
    }

    public function forceDelete(User $user, CompanyProfile $companyProfile): bool
    {
        return false;
    }
}
