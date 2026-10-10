<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class EmployeeController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Employee::class);
        $query = Employee::query();

        if (! $request->user()->hasPermission('employees.view')) {
            $employeeId = $request->user()->employee()->value('id');
            $query->where(function (Builder $employees) use ($employeeId): void {
                $employees->where('id', $employeeId ?? 0);

                if ($employeeId !== null) {
                    $employees->orWhere('supervisor_employee_id', $employeeId);
                }
            });
        }

        return EmployeeResource::collection($this->paginate($request, $query));
    }

    public function show(Employee $employee): EmployeeResource
    {
        Gate::authorize('view', $employee);

        return new EmployeeResource($employee);
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = Employee::query()->create($request->validated());

        return (new EmployeeResource($employee->fresh()))->response()->setStatusCode(201);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $employee->update($request->validated());

        return new EmployeeResource($employee->fresh());
    }
}
