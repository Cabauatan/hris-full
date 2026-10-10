<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class ExportReportRequest extends GetReportRequest
{
    protected function ability(): string
    {
        return 'exportReport';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function paginationRules(): array
    {
        return ['per_page' => ['prohibited'], 'page' => ['prohibited']];
    }
}
