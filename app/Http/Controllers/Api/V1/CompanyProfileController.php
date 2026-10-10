<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\UpdateCompanyProfileRequest;
use App\Http\Resources\CompanyProfileResource;
use App\Models\CompanyProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CompanyProfileController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', CompanyProfile::class);
        $query = CompanyProfile::query();

        return CompanyProfileResource::collection($this->paginate($request, $query));
    }

    public function show(CompanyProfile $companyProfile): CompanyProfileResource
    {
        Gate::authorize('view', $companyProfile);

        return new CompanyProfileResource($companyProfile);
    }

    public function update(UpdateCompanyProfileRequest $request, CompanyProfile $companyProfile): CompanyProfileResource
    {
        $companyProfile->update($request->validated());

        return new CompanyProfileResource($companyProfile->fresh());
    }
}
