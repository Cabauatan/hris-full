<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\ApproveTravelOrderRequest;
use App\Http\Requests\CancelTravelOrderRequest;
use App\Http\Requests\RejectTravelOrderRequest;
use App\Http\Requests\StoreTravelOrderRequest;
use App\Http\Resources\TravelOrderRequestResource;
use App\Models\TravelOrderRequest;
use App\Services\Approval\ApprovalAuthorityResolver;
use App\Services\TravelOrder\TravelOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class TravelOrderRequestController extends ApiController
{
    public function __construct(private TravelOrderService $service) {}

    public function index(Request $request, ApprovalAuthorityResolver $authority): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', TravelOrderRequest::class);
        $query = $this->visibleEmployeeRecords($request, TravelOrderRequest::query(), $request->user()->hasPermission('employees.view'), true, $authority->delegatedEmployees($request->user(), ApprovalAuthorityResolver::TYPE_TRAVEL_ORDER));

        return TravelOrderRequestResource::collection($this->paginate($request, $query));
    }

    public function show(TravelOrderRequest $travelOrderRequest): TravelOrderRequestResource
    {
        Gate::authorize('view', $travelOrderRequest);

        return new TravelOrderRequestResource($travelOrderRequest->loadMissing('approvalActions'));
    }

    public function store(StoreTravelOrderRequest $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);
        $input = $request->validated();
        $record = $this->service->submit($employee, $request->user(), $input['start_at'], $input['end_at'], $input['destination'], $input['purpose'], $input['document_path'] ?? null);

        return (new TravelOrderRequestResource($record->loadMissing('approvalActions')))->response()->setStatusCode(201);
    }

    public function approve(ApproveTravelOrderRequest $request, TravelOrderRequest $travelOrderRequest): TravelOrderRequestResource
    {
        $record = $this->service->approve($travelOrderRequest, $request->user(), $request->validated('remarks'));

        return new TravelOrderRequestResource($record->loadMissing('approvalActions'));
    }

    public function reject(RejectTravelOrderRequest $request, TravelOrderRequest $travelOrderRequest): TravelOrderRequestResource
    {
        $record = $this->service->reject($travelOrderRequest, $request->user(), $request->validated('remarks'));

        return new TravelOrderRequestResource($record->loadMissing('approvalActions'));
    }

    public function cancel(CancelTravelOrderRequest $request, TravelOrderRequest $travelOrderRequest): TravelOrderRequestResource
    {
        $record = $this->service->cancel($travelOrderRequest, $request->user(), $request->validated('reason'));

        return new TravelOrderRequestResource($record->loadMissing('approvalActions'));
    }
}
