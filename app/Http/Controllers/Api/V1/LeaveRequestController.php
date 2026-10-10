<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\ApproveLeaveRequest;
use App\Http\Requests\CancelLeaveRequest;
use App\Http\Requests\RejectLeaveRequest;
use App\Http\Requests\StoreLeaveRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Approval\ApprovalAuthorityResolver;
use App\Services\Leave\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class LeaveRequestController extends ApiController
{
    public function __construct(private LeaveService $service) {}

    public function index(Request $request, ApprovalAuthorityResolver $authority): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', LeaveRequest::class);
        $query = $this->visibleEmployeeRecords($request, LeaveRequest::query(), $request->user()->hasPermission('employees.view'), true, $authority->delegatedEmployees($request->user(), ApprovalAuthorityResolver::TYPE_LEAVE));

        return LeaveRequestResource::collection($this->paginate($request, $query));
    }

    public function show(LeaveRequest $leaveRequest): LeaveRequestResource
    {
        Gate::authorize('view', $leaveRequest);

        return new LeaveRequestResource($leaveRequest->loadMissing('approvalActions'));
    }

    public function store(StoreLeaveRequest $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);
        $input = $request->validated();
        $record = $this->service->submit($employee, LeaveType::query()->findOrFail($input['leave_type_id']), $request->user(), $input['start_date'], $input['end_date'], $input['day_portion'], (string) $input['requested_units'], $input['reason'], $input['document_path'] ?? null);

        return (new LeaveRequestResource($record->loadMissing('approvalActions')))->response()->setStatusCode(201);
    }

    public function approve(ApproveLeaveRequest $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $record = $this->service->approve($leaveRequest, $request->user(), (string) $request->validated('approved_units'), $request->validated('remarks'));

        return new LeaveRequestResource($record->loadMissing('approvalActions'));
    }

    public function reject(RejectLeaveRequest $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $record = $this->service->reject($leaveRequest, $request->user(), $request->validated('remarks'));

        return new LeaveRequestResource($record->loadMissing('approvalActions'));
    }

    public function cancel(CancelLeaveRequest $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $record = $this->service->cancel($leaveRequest, $request->user(), $request->validated('reason'));

        return new LeaveRequestResource($record->loadMissing('approvalActions'));
    }
}
