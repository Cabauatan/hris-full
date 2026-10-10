<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\ExportReportRequest;
use App\Http\Requests\GetReportRequest;
use App\Http\Requests\IndexReportRequest;
use App\Http\Resources\ReportResource;
use App\Services\Report\ReportCsvExporter;
use App\Services\Report\ReportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends ApiController
{
    public function index(IndexReportRequest $request, ReportService $reports): ReportResource
    {
        return new ReportResource($reports->catalog($request->user()));
    }

    public function show(GetReportRequest $request, string $report, ReportService $reports): ReportResource
    {
        return new ReportResource($reports->preview($request->user(), $report, $request->validated()));
    }

    public function export(ExportReportRequest $request, string $report, ReportCsvExporter $exporter): StreamedResponse
    {
        return $exporter->download($request->user(), $report, $request->validated());
    }
}
