<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StorePayrollPeriodRequest;
use App\Http\Requests\UpdatePayrollPeriodRequest;
use App\Http\Resources\PayrollPeriodResource;
use App\Models\PayrollPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class PayrollPeriodController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PayrollPeriod::class);
        $query = PayrollPeriod::query();

        return PayrollPeriodResource::collection($this->paginate($request, $query));
    }

    public function show(PayrollPeriod $payrollPeriod): PayrollPeriodResource
    {
        Gate::authorize('view', $payrollPeriod);

        return new PayrollPeriodResource($payrollPeriod);
    }

    public function store(StorePayrollPeriodRequest $request): JsonResponse
    {
        $payrollPeriod = PayrollPeriod::query()->create($request->validated());

        return (new PayrollPeriodResource($payrollPeriod->fresh()))->response()->setStatusCode(201);
    }

    public function update(UpdatePayrollPeriodRequest $request, PayrollPeriod $payrollPeriod): PayrollPeriodResource
    {
        $payrollPeriod->update($request->validated());

        return new PayrollPeriodResource($payrollPeriod->fresh());
    }
}
