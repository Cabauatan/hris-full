<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\JobOpeningResource;
use App\Models\JobOpening;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CareerJobOpeningController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $input = $request->validate([
            'search' => ['nullable', 'string', 'max:180'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = JobOpening::query()->accepting();
        if (isset($input['search']) && trim($input['search']) !== '') {
            $query->where(function (Builder $search) use ($input): void {
                $search->where('title', 'like', '%'.trim($input['search']).'%')
                    ->orWhere('location', 'like', '%'.trim($input['search']).'%');
            });
        }

        return JobOpeningResource::collection($query->orderByDesc('id')->paginate($input['per_page'] ?? 15)->withQueryString());
    }

    public function show(JobOpening $jobOpening): JobOpeningResource
    {
        abort_unless($jobOpening->acceptingApplications(), 404);

        return new JobOpeningResource($jobOpening);
    }
}
