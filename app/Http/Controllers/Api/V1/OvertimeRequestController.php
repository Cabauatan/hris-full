<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\ApproveOvertimeRequest;
use App\Http\Requests\CancelOvertimeRequest;
use App\Http\Requests\RejectOvertimeRequest;
use App\Http\Requests\StoreOvertimeRequest;
use App\Http\Resources\OvertimeRequestResource;
use App\Models\OvertimeRequest;
use App\Services\Approval\ApprovalAuthorityResolver;
use App\Services\Overtime\OvertimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class OvertimeRequestController extends ApiController
{
    public function __construct(private OvertimeService $service) {}

    public function index(Request $request, ApprovalAuthorityResolver $authority): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', OvertimeRequest::class);
        $query = $this->visibleEmployeeRecords($request, OvertimeRequest::query(), $request->user()->hasPermission('employees.view'), true, $authority->delegatedEmployees($request->user(), ApprovalAuthorityResolver::TYPE_OVERTIME));

        return OvertimeRequestResource::collection($this->paginate($request, $query));
    }

    public function show(OvertimeRequest $overtimeRequest): OvertimeRequestResource
    {
        Gate::authorize('view', $overtimeRequest);

        return new OvertimeRequestResource($overtimeRequest->loadMissing('approvalActions'));
    }

    public function store(StoreOvertimeRequest $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);
        $input = $request->validated();
        $record = $this->service->submit($employee, $request->user(), $input['start_at'], $input['end_at'], $input['reason'], $input['document_path'] ?? null);

        return (new OvertimeRequestResource($record->loadMissing('approvalActions')))->response()->setStatusCode(201);
    }

    public function approve(ApproveOvertimeRequest $request, OvertimeRequest $overtimeRequest): OvertimeRequestResource
    {
        $record = $this->service->approve($overtimeRequest, $request->user(), (int) $request->validated('approved_minutes'), $request->validated('remarks'));

        return new OvertimeRequestResource($record->loadMissing('approvalActions'));
    }

    public function reject(RejectOvertimeRequest $request, OvertimeRequest $overtimeRequest): OvertimeRequestResource
    {
        $record = $this->service->reject($overtimeRequest, $request->user(), $request->validated('remarks'));

        return new OvertimeRequestResource($record->loadMissing('approvalActions'));
    }

    public function cancel(CancelOvertimeRequest $request, OvertimeRequest $overtimeRequest): OvertimeRequestResource
    {
        $record = $this->service->cancel($overtimeRequest, $request->user(), $request->validated('reason'));

        return new OvertimeRequestResource($record->loadMissing('approvalActions'));
    }
}
