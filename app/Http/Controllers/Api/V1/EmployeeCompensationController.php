<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreEmployeeCompensationRequest;
use App\Http\Requests\UpdateEmployeeCompensationRequest;
use App\Http\Resources\EmployeeCompensationResource;
use App\Models\EmployeeCompensation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class EmployeeCompensationController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', EmployeeCompensation::class);
        $query = EmployeeCompensation::query();

        return EmployeeCompensationResource::collection($this->paginate($request, $query));
    }

    public function show(EmployeeCompensation $employeeCompensation): EmployeeCompensationResource
    {
        Gate::authorize('view', $employeeCompensation);

        return new EmployeeCompensationResource($employeeCompensation);
    }

    public function store(StoreEmployeeCompensationRequest $request): JsonResponse
    {
        $employeeCompensation = EmployeeCompensation::query()->create($request->validated() + ['created_by_user_id' => $request->user()->id]);

        return (new EmployeeCompensationResource($employeeCompensation->fresh()))->response()->setStatusCode(201);
    }

    public function update(UpdateEmployeeCompensationRequest $request, EmployeeCompensation $employeeCompensation): EmployeeCompensationResource
    {
        $employeeCompensation->update($request->validated());

        return new EmployeeCompensationResource($employeeCompensation->fresh());
    }
}
