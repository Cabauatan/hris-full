<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreEmployeeScheduleAssignmentRequest;
use App\Http\Requests\UpdateEmployeeScheduleAssignmentRequest;
use App\Http\Resources\EmployeeScheduleAssignmentResource;
use App\Models\EmployeeScheduleAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class EmployeeScheduleAssignmentController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', EmployeeScheduleAssignment::class);
        $query = EmployeeScheduleAssignment::query();

        $query = $this->visibleEmployeeRecords($request, $query, $request->user()->hasPermission('schedules.assign'));

        return EmployeeScheduleAssignmentResource::collection($this->paginate($request, $query));
    }

    public function show(EmployeeScheduleAssignment $employeeScheduleAssignment): EmployeeScheduleAssignmentResource
    {
        Gate::authorize('view', $employeeScheduleAssignment);

        return new EmployeeScheduleAssignmentResource($employeeScheduleAssignment);
    }

    public function store(StoreEmployeeScheduleAssignmentRequest $request): JsonResponse
    {
        $employeeScheduleAssignment = EmployeeScheduleAssignment::query()->create($request->validated() + ['created_by_user_id' => $request->user()->id]);

        return (new EmployeeScheduleAssignmentResource($employeeScheduleAssignment->fresh()))->response()->setStatusCode(201);
    }

    public function update(UpdateEmployeeScheduleAssignmentRequest $request, EmployeeScheduleAssignment $employeeScheduleAssignment): EmployeeScheduleAssignmentResource
    {
        $employeeScheduleAssignment->update($request->validated());

        return new EmployeeScheduleAssignmentResource($employeeScheduleAssignment->fresh());
    }
}
