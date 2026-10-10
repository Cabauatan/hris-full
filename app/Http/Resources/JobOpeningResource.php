<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;

class JobOpeningResource extends ApiResource
{
    protected const FIELDS = [
        'id', 'title', 'description', 'requirements', 'location', 'employment_type',
        'vacancies', 'status', 'closes_on', 'monthly_salary', 'created_at',
    ];

    protected const DATES = ['closes_on'];

    public function toArray(Request $request): array
    {
        $data = parent::toArray($request) + [
            'salary_currency' => 'PHP',
            'accepting_applications' => $this->resource->acceptingApplications(),
        ];
        if ($request->routeIs('api.v1.recruitment.job-openings.*')) {
            $actor = $request->user();
            $data += [
                'created_by' => $this->created_by,
                'updated_at' => $this->updated_at?->toAtomString(),
                'abilities' => ['update' => $actor instanceof User && $actor->can('update', $this->resource)],
            ];
        }

        return $data;
    }
}
