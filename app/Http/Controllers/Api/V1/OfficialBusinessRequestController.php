<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\ApproveOfficialBusinessRequest;
use App\Http\Requests\CancelOfficialBusinessRequest;
use App\Http\Requests\RejectOfficialBusinessRequest;
use App\Http\Requests\StoreOfficialBusinessRequest;
use App\Http\Resources\OfficialBusinessRequestResource;
use App\Models\OfficialBusinessRequest;
use App\Services\Approval\ApprovalAuthorityResolver;
use App\Services\OfficialBusiness\OfficialBusinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class OfficialBusinessRequestController extends ApiController
{
    public function __construct(private OfficialBusinessService $service) {}

    public function index(Request $request, ApprovalAuthorityResolver $authority): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', OfficialBusinessRequest::class);
        $query = $this->visibleEmployeeRecords($request, OfficialBusinessRequest::query(), $request->user()->hasPermission('employees.view'), true, $authority->delegatedEmployees($request->user(), ApprovalAuthorityResolver::TYPE_OFFICIAL_BUSINESS));

        return OfficialBusinessRequestResource::collection($this->paginate($request, $query));
    }

    public function show(OfficialBusinessRequest $officialBusinessRequest): OfficialBusinessRequestResource
    {
        Gate::authorize('view', $officialBusinessRequest);

        return new OfficialBusinessRequestResource($officialBusinessRequest->loadMissing('approvalActions'));
    }

    public function store(StoreOfficialBusinessRequest $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);
        $input = $request->validated();
        $record = $this->service->submit($employee, $request->user(), $input['start_at'], $input['end_at'], $input['purpose'], $input['destination'] ?? null, $input['document_path'] ?? null);

        return (new OfficialBusinessRequestResource($record->loadMissing('approvalActions')))->response()->setStatusCode(201);
    }

    public function approve(ApproveOfficialBusinessRequest $request, OfficialBusinessRequest $officialBusinessRequest): OfficialBusinessRequestResource
    {
        $record = $this->service->approve($officialBusinessRequest, $request->user(), $request->validated('remarks'));

        return new OfficialBusinessRequestResource($record->loadMissing('approvalActions'));
    }

    public function reject(RejectOfficialBusinessRequest $request, OfficialBusinessRequest $officialBusinessRequest): OfficialBusinessRequestResource
    {
        $record = $this->service->reject($officialBusinessRequest, $request->user(), $request->validated('remarks'));

        return new OfficialBusinessRequestResource($record->loadMissing('approvalActions'));
    }

    public function cancel(CancelOfficialBusinessRequest $request, OfficialBusinessRequest $officialBusinessRequest): OfficialBusinessRequestResource
    {
        $record = $this->service->cancel($officialBusinessRequest, $request->user(), $request->validated('reason'));

        return new OfficialBusinessRequestResource($record->loadMissing('approvalActions'));
    }
}
