<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\CompletePayrollRunRequest;
use App\Http\Requests\FinalizePayrollRunRequest;
use App\Http\Requests\StartPayrollRunRequest;
use App\Http\Requests\StorePayrollRunRequest;
use App\Http\Resources\PayrollRunResource;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class PayrollRunController extends ApiController
{
    public function __construct(private PayrollRunService $payrollRuns) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PayrollRun::class);
        $query = PayrollRun::query();

        return PayrollRunResource::collection($this->paginate($request, $query));
    }

    public function show(PayrollRun $payrollRun): PayrollRunResource
    {
        Gate::authorize('view', $payrollRun);

        return new PayrollRunResource($payrollRun);
    }

    public function store(StorePayrollRunRequest $request): JsonResponse
    {
        $payrollRun = PayrollRun::query()->create($request->validated() + ['status' => 'draft', 'created_by_user_id' => $request->user()->id]);

        return (new PayrollRunResource($payrollRun->fresh()))->response()->setStatusCode(201);
    }

    public function start(StartPayrollRunRequest $request, PayrollRun $payrollRun): PayrollRunResource
    {
        return new PayrollRunResource($this->payrollRuns->start($payrollRun));
    }

    public function complete(CompletePayrollRunRequest $request, PayrollRun $payrollRun): PayrollRunResource
    {
        return new PayrollRunResource($this->payrollRuns->complete($payrollRun));
    }

    public function finalize(FinalizePayrollRunRequest $request, PayrollRun $payrollRun): PayrollRunResource
    {
        return new PayrollRunResource($this->payrollRuns->finalize($payrollRun, $request->user()));
    }
}
