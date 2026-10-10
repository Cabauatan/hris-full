<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class DepartmentController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Department::class);
        $query = Department::query();

        return DepartmentResource::collection($this->paginate($request, $query));
    }

    public function show(Department $department): DepartmentResource
    {
        Gate::authorize('view', $department);

        return new DepartmentResource($department);
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $department = Department::query()->create($request->validated());

        return (new DepartmentResource($department->fresh()))->response()->setStatusCode(201);
    }

    public function update(UpdateDepartmentRequest $request, Department $department): DepartmentResource
    {
        $department->update($request->validated());

        return new DepartmentResource($department->fresh());
    }
}
