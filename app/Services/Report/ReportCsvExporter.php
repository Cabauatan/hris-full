<?php

namespace App\Services\Report;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportCsvExporter
{
    public function __construct(private readonly ReportService $reports) {}

    public function download(User $user, string $report, array $filters): StreamedResponse
    {
        Gate::forUser($user)->authorize('exportReport', [$report]);
        $definition = $this->reports->definition($report);
        $context = $this->reports->context($user, $definition, $filters);
        $query = $this->reports->query($user, $definition, $context);
        $maximumId = (clone $query)->max('id') ?? 0;
        $query->where('id', '<=', $maximumId);

        if ((clone $query)->count() > ReportService::MAX_EXPORT_ROWS) {
            throw ValidationException::withMessages(['export' => 'The export is limited to '.ReportService::MAX_EXPORT_ROWS.' records. Narrow your filters and try again.']);
        }

        $filename = 'hris-'.$report.'-'.CarbonImmutable::now(config('app.timezone'))->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query, $definition): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new RuntimeException('Unable to open the report stream.');
            }
            try {
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, array_column($definition['columns'], 'label'), ',', '"', '', "\r\n");
                foreach ($query->lazyById(500) as $record) {
                    $row = $this->reports->row($record, $definition);
                    $cells = [];
                    foreach ($definition['columns'] as $column) {
                        $value = $row[$column['key']];
                        $cell = $value === null ? '' : (is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value);
                        if ($column['type'] === 'text' && preg_match('/^[\s\x00-\x1F]*[=+\-@]/u', $cell)) {
                            $cell = "'".$cell;
                        }
                        $cells[] = $cell;
                    }
                    fputcsv($stream, $cells, ',', '"', '', "\r\n");
                }
            } finally {
                fclose($stream);
            }
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
