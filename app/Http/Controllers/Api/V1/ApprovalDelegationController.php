<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreApprovalDelegationRequest;
use App\Http\Requests\UpdateApprovalDelegationRequest;
use App\Http\Resources\ApprovalDelegationResource;
use App\Models\ApprovalDelegation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ApprovalDelegationController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ApprovalDelegation::class);
        $query = ApprovalDelegation::query();

        if (! $request->user()->hasPermission('employees.view')) {
            $query->where(function (Builder $query) use ($request): void {
                $query->where('delegator_user_id', $request->user()->id)
                    ->orWhere('delegate_user_id', $request->user()->id);
            });
        }

        return ApprovalDelegationResource::collection($this->paginate($request, $query));
    }

    public function show(ApprovalDelegation $approvalDelegation): ApprovalDelegationResource
    {
        Gate::authorize('view', $approvalDelegation);

        return new ApprovalDelegationResource($approvalDelegation);
    }

    public function store(StoreApprovalDelegationRequest $request): JsonResponse
    {
        $approvalDelegation = ApprovalDelegation::query()->create($request->validated() + ['created_by_user_id' => $request->user()->id]);

        return (new ApprovalDelegationResource($approvalDelegation->fresh()))->response()->setStatusCode(201);
    }

    public function update(UpdateApprovalDelegationRequest $request, ApprovalDelegation $approvalDelegation): ApprovalDelegationResource
    {
        $approvalDelegation->update($request->validated());

        return new ApprovalDelegationResource($approvalDelegation->fresh());
    }
}
