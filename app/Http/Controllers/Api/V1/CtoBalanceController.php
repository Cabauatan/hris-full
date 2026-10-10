<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\AdjustCtoCreditRequest;
use App\Http\Resources\CtoBalanceResource;
use App\Http\Resources\CtoCreditTransactionResource;
use App\Models\CtoBalance;
use App\Models\Employee;
use App\Services\Cto\CtoCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CtoBalanceController extends ApiController
{
    public function __construct(private CtoCreditService $credits) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', CtoBalance::class);
        $query = CtoBalance::query();

        $query = $this->visibleEmployeeRecords($request, $query, $request->user()->hasPermission('employees.view'));

        return CtoBalanceResource::collection($this->paginate($request, $query));
    }

    public function show(CtoBalance $ctoBalance): CtoBalanceResource
    {
        Gate::authorize('view', $ctoBalance);

        return new CtoBalanceResource($ctoBalance);
    }

    public function adjust(AdjustCtoCreditRequest $request): JsonResponse
    {
        $input = $request->validated();
        $transaction = $this->credits->adjust(Employee::query()->findOrFail($input['employee_id']), (int) $input['minutes'], $input['effective_date'], $request->user(), $input['remarks'] ?? null);

        return (new CtoCreditTransactionResource($transaction))->response()->setStatusCode(201);
    }
}
