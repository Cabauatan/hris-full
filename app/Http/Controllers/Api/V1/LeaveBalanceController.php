<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\AdjustLeaveCreditRequest;
use App\Http\Resources\LeaveBalanceResource;
use App\Http\Resources\LeaveCreditTransactionResource;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Services\Leave\LeaveCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class LeaveBalanceController extends ApiController
{
    public function __construct(private LeaveCreditService $credits) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', LeaveBalance::class);
        $query = LeaveBalance::query();

        $query = $this->visibleEmployeeRecords($request, $query, $request->user()->hasPermission('employees.view'));

        return LeaveBalanceResource::collection($this->paginate($request, $query));
    }

    public function show(LeaveBalance $leaveBalance): LeaveBalanceResource
    {
        Gate::authorize('view', $leaveBalance);

        return new LeaveBalanceResource($leaveBalance);
    }

    public function adjust(AdjustLeaveCreditRequest $request): JsonResponse
    {
        $input = $request->validated();
        $transaction = $this->credits->adjust(Employee::query()->findOrFail($input['employee_id']), LeaveType::query()->findOrFail($input['leave_type_id']), (string) $input['units'], $input['effective_date'], $request->user(), $input['remarks'] ?? null);

        return (new LeaveCreditTransactionResource($transaction))->response()->setStatusCode(201);
    }
}
