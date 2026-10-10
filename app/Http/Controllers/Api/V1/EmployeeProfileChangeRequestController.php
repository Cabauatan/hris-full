<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\ApplyEmployeeProfileChangeRequest;
use App\Http\Requests\ApproveEmployeeProfileChangeRequest;
use App\Http\Requests\CancelEmployeeProfileChangeRequest;
use App\Http\Requests\RejectEmployeeProfileChangeRequest;
use App\Http\Requests\StoreEmployeeProfileChangeRequest;
use App\Http\Resources\EmployeeProfileChangeRequestResource;
use App\Models\EmployeeProfileChangeRequest;
use App\Services\EmployeeProfile\EmployeeProfileChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class EmployeeProfileChangeRequestController extends ApiController
{
    public function __construct(private EmployeeProfileChangeService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', EmployeeProfileChangeRequest::class);
        $query = $this->visibleEmployeeRecords($request, EmployeeProfileChangeRequest::query(), $request->user()->hasPermission('employees.view'), false);
        $query->with('items');

        return EmployeeProfileChangeRequestResource::collection($this->paginate($request, $query));
    }

    public function show(EmployeeProfileChangeRequest $employeeProfileChangeRequest): EmployeeProfileChangeRequestResource
    {
        Gate::authorize('view', $employeeProfileChangeRequest);

        return new EmployeeProfileChangeRequestResource($employeeProfileChangeRequest->loadMissing('items'));
    }

    public function store(StoreEmployeeProfileChangeRequest $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);
        $input = $request->validated();
        $record = $this->service->submit($employee, $request->user(), $input['changes'], $input['reason'] ?? null, $input['document_path'] ?? null);

        return (new EmployeeProfileChangeRequestResource($record->loadMissing('items')))->response()->setStatusCode(201);
    }

    public function approve(ApproveEmployeeProfileChangeRequest $request, EmployeeProfileChangeRequest $employeeProfileChangeRequest): EmployeeProfileChangeRequestResource
    {
        $record = $this->service->approve($employeeProfileChangeRequest, $request->user(), $request->validated('remarks'));

        return new EmployeeProfileChangeRequestResource($record->loadMissing('items'));
    }

    public function reject(RejectEmployeeProfileChangeRequest $request, EmployeeProfileChangeRequest $employeeProfileChangeRequest): EmployeeProfileChangeRequestResource
    {
        $record = $this->service->reject($employeeProfileChangeRequest, $request->user(), $request->validated('remarks'));

        return new EmployeeProfileChangeRequestResource($record->loadMissing('items'));
    }

    public function cancel(CancelEmployeeProfileChangeRequest $request, EmployeeProfileChangeRequest $employeeProfileChangeRequest): EmployeeProfileChangeRequestResource
    {
        $record = $this->service->cancel($employeeProfileChangeRequest, $request->validated('reason'));

        return new EmployeeProfileChangeRequestResource($record->loadMissing('items'));
    }

    public function apply(ApplyEmployeeProfileChangeRequest $request, EmployeeProfileChangeRequest $employeeProfileChangeRequest): EmployeeProfileChangeRequestResource
    {
        $record = $this->service->apply($employeeProfileChangeRequest, $request->user());

        return new EmployeeProfileChangeRequestResource($record->loadMissing('items'));
    }
}
