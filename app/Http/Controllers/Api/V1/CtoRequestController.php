<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\ApproveCtoRequest;
use App\Http\Requests\CancelCtoRequest;
use App\Http\Requests\RejectCtoRequest;
use App\Http\Requests\StoreCtoRequest;
use App\Http\Resources\CtoRequestResource;
use App\Models\CtoRequest;
use App\Services\Approval\ApprovalAuthorityResolver;
use App\Services\Cto\CtoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CtoRequestController extends ApiController
{
    public function __construct(private CtoService $service) {}

    public function index(Request $request, ApprovalAuthorityResolver $authority): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', CtoRequest::class);
        $query = $this->visibleEmployeeRecords($request, CtoRequest::query(), $request->user()->hasPermission('employees.view'), true, $authority->delegatedEmployees($request->user(), ApprovalAuthorityResolver::TYPE_CTO));

        return CtoRequestResource::collection($this->paginate($request, $query));
    }

    public function show(CtoRequest $ctoRequest): CtoRequestResource
    {
        Gate::authorize('view', $ctoRequest);

        return new CtoRequestResource($ctoRequest->loadMissing('approvalActions'));
    }

    public function store(StoreCtoRequest $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);
        $input = $request->validated();
        $record = $this->service->submit($employee, $request->user(), $input['start_at'], $input['end_at'], $input['reason']);

        return (new CtoRequestResource($record->loadMissing('approvalActions')))->response()->setStatusCode(201);
    }

    public function approve(ApproveCtoRequest $request, CtoRequest $ctoRequest): CtoRequestResource
    {
        $record = $this->service->approve($ctoRequest, $request->user(), (int) $request->validated('approved_minutes'), $request->validated('remarks'));

        return new CtoRequestResource($record->loadMissing('approvalActions'));
    }

    public function reject(RejectCtoRequest $request, CtoRequest $ctoRequest): CtoRequestResource
    {
        $record = $this->service->reject($ctoRequest, $request->user(), $request->validated('remarks'));

        return new CtoRequestResource($record->loadMissing('approvalActions'));
    }

    public function cancel(CancelCtoRequest $request, CtoRequest $ctoRequest): CtoRequestResource
    {
        $record = $this->service->cancel($ctoRequest, $request->user(), $request->validated('reason'));

        return new CtoRequestResource($record->loadMissing('approvalActions'));
    }
}
