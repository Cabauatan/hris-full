<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\GeneratePayslipRequest;
use App\Http\Requests\MarkPayslipViewedRequest;
use App\Http\Requests\PublishPayslipRequest;
use App\Http\Resources\PayslipResource;
use App\Models\PayrollEntry;
use App\Models\Payslip;
use App\Services\Payslip\PayslipDocumentService;
use App\Services\Payslip\PayslipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayslipController extends ApiController
{
    public function __construct(private PayslipService $payslips, private PayslipDocumentService $documents) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Payslip::class);
        $query = Payslip::query();

        if (! $request->user()->hasPermission('employees.view')) {
            $query = $this->visibleEmployeeRecords($request, $query, false, false)->whereNotNull('published_at');
        }

        return PayslipResource::collection($this->paginate($request, $query));
    }

    public function show(Payslip $payslip): PayslipResource
    {
        Gate::authorize('view', $payslip);

        return new PayslipResource($payslip);
    }

    public function generate(GeneratePayslipRequest $request, PayrollEntry $payrollEntry): JsonResponse
    {
        return (new PayslipResource($this->documents->generate($payrollEntry, $request->user())))->response()->setStatusCode(201);
    }

    public function publish(PublishPayslipRequest $request, Payslip $payslip): PayslipResource
    {
        return new PayslipResource($this->payslips->publish($payslip, $request->user()));
    }

    public function markViewed(MarkPayslipViewedRequest $request, Payslip $payslip): PayslipResource
    {
        return new PayslipResource($this->payslips->markViewed($payslip));
    }

    public function download(Payslip $payslip): StreamedResponse
    {
        Gate::authorize('view', $payslip);
        abort_unless($this->documents->ownsPath($payslip->file_path) && Storage::disk('local')->exists($payslip->file_path), 404);

        return Storage::disk('local')->download($payslip->file_path, 'payslip-'.$payslip->id.'.html', ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
