<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\PreparePayrollEntryRequest;
use App\Http\Requests\ReplacePayrollEntryItemsRequest;
use App\Http\Resources\PayrollEntryResource;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class PayrollEntryController extends ApiController
{
    public function __construct(private PayrollEntryService $entries) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PayrollEntry::class);
        $query = PayrollEntry::query();

        $query->with('items');

        return PayrollEntryResource::collection($this->paginate($request, $query));
    }

    public function show(PayrollEntry $payrollEntry): PayrollEntryResource
    {
        Gate::authorize('view', $payrollEntry);

        return new PayrollEntryResource($payrollEntry->loadMissing('items'));
    }

    public function prepare(PreparePayrollEntryRequest $request, PayrollRun $payrollRun): JsonResponse
    {
        $input = $request->validated();
        $entry = $this->entries->prepare($payrollRun, Employee::query()->findOrFail($input['employee_id']), $input['compensation_reference_date']);

        return (new PayrollEntryResource($entry))->response()->setStatusCode(201);
    }

    public function replaceItems(ReplacePayrollEntryItemsRequest $request, PayrollEntry $payrollEntry): PayrollEntryResource
    {
        return new PayrollEntryResource($this->entries->replaceItems($payrollEntry, $request->validated('items')));
    }
}
