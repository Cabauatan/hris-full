<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreLeaveTypeRequest;
use App\Http\Requests\UpdateLeaveTypeRequest;
use App\Http\Resources\LeaveTypeResource;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class LeaveTypeController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', LeaveType::class);
        $query = LeaveType::query();

        return LeaveTypeResource::collection($this->paginate($request, $query));
    }

    public function show(LeaveType $leaveType): LeaveTypeResource
    {
        Gate::authorize('view', $leaveType);

        return new LeaveTypeResource($leaveType);
    }

    public function store(StoreLeaveTypeRequest $request): JsonResponse
    {
        $leaveType = LeaveType::query()->create($request->validated());

        return (new LeaveTypeResource($leaveType->fresh()))->response()->setStatusCode(201);
    }

    public function update(UpdateLeaveTypeRequest $request, LeaveType $leaveType): LeaveTypeResource
    {
        $leaveType->update($request->validated());

        return new LeaveTypeResource($leaveType->fresh());
    }
}
