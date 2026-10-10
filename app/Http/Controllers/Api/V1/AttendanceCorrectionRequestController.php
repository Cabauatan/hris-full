<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\ApproveAttendanceCorrectionRequest;
use App\Http\Requests\CancelAttendanceCorrectionRequest;
use App\Http\Requests\RejectAttendanceCorrectionRequest;
use App\Http\Requests\StoreAttendanceCorrectionRequest;
use App\Http\Resources\AttendanceCorrectionRequestResource;
use App\Models\AttendanceCorrectionRequest;
use App\Services\Approval\ApprovalAuthorityResolver;
use App\Services\AttendanceCorrection\AttendanceCorrectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AttendanceCorrectionRequestController extends ApiController
{
    public function __construct(private AttendanceCorrectionService $service) {}

    public function index(Request $request, ApprovalAuthorityResolver $authority): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AttendanceCorrectionRequest::class);
        $query = $this->visibleEmployeeRecords($request, AttendanceCorrectionRequest::query(), $request->user()->hasPermission('employees.view'), true, $authority->delegatedEmployees($request->user(), ApprovalAuthorityResolver::TYPE_ATTENDANCE_CORRECTION));

        return AttendanceCorrectionRequestResource::collection($this->paginate($request, $query));
    }

    public function show(AttendanceCorrectionRequest $attendanceCorrectionRequest): AttendanceCorrectionRequestResource
    {
        Gate::authorize('view', $attendanceCorrectionRequest);

        return new AttendanceCorrectionRequestResource($attendanceCorrectionRequest->loadMissing('approvalActions'));
    }

    public function store(StoreAttendanceCorrectionRequest $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);
        $input = $request->validated();
        $record = $this->service->submit($employee, $request->user(), $input['attendance_date'], $input['requested_time_in'] ?? null, $input['requested_time_out'] ?? null, $input['reason'], isset($input['daily_attendance_id']) ? (int) $input['daily_attendance_id'] : null, $input['document_path'] ?? null);

        return (new AttendanceCorrectionRequestResource($record->loadMissing('approvalActions')))->response()->setStatusCode(201);
    }

    public function approve(ApproveAttendanceCorrectionRequest $request, AttendanceCorrectionRequest $attendanceCorrectionRequest): AttendanceCorrectionRequestResource
    {
        $record = $this->service->approve($attendanceCorrectionRequest, $request->user(), $request->validated('remarks'));

        return new AttendanceCorrectionRequestResource($record->loadMissing('approvalActions'));
    }

    public function reject(RejectAttendanceCorrectionRequest $request, AttendanceCorrectionRequest $attendanceCorrectionRequest): AttendanceCorrectionRequestResource
    {
        $record = $this->service->reject($attendanceCorrectionRequest, $request->user(), $request->validated('remarks'));

        return new AttendanceCorrectionRequestResource($record->loadMissing('approvalActions'));
    }

    public function cancel(CancelAttendanceCorrectionRequest $request, AttendanceCorrectionRequest $attendanceCorrectionRequest): AttendanceCorrectionRequestResource
    {
        $record = $this->service->cancel($attendanceCorrectionRequest, $request->user(), $request->validated('reason'));

        return new AttendanceCorrectionRequestResource($record->loadMissing('approvalActions'));
    }
}
