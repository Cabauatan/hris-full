<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreEmployeeEmploymentHistoryRequest;
use App\Http\Requests\UpdateEmployeeEmploymentHistoryRequest;
use App\Http\Resources\EmployeeEmploymentHistoryResource;
use App\Models\EmployeeEmploymentHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class EmployeeEmploymentHistoryController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', EmployeeEmploymentHistory::class);
        $query = EmployeeEmploymentHistory::query();

        return EmployeeEmploymentHistoryResource::collection($this->paginate($request, $query));
    }

    public function show(EmployeeEmploymentHistory $employeeEmploymentHistory): EmployeeEmploymentHistoryResource
    {
        Gate::authorize('view', $employeeEmploymentHistory);

        return new EmployeeEmploymentHistoryResource($employeeEmploymentHistory);
    }

    public function store(StoreEmployeeEmploymentHistoryRequest $request): JsonResponse
    {
        $employeeEmploymentHistory = EmployeeEmploymentHistory::query()->create($request->validated() + ['created_by_user_id' => $request->user()->id]);

        return (new EmployeeEmploymentHistoryResource($employeeEmploymentHistory->fresh()))->response()->setStatusCode(201);
    }

    public function update(UpdateEmployeeEmploymentHistoryRequest $request, EmployeeEmploymentHistory $employeeEmploymentHistory): EmployeeEmploymentHistoryResource
    {
        $employeeEmploymentHistory->update($request->validated());

        return new EmployeeEmploymentHistoryResource($employeeEmploymentHistory->fresh());
    }
}
