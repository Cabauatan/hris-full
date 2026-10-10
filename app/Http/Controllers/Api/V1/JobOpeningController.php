<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreJobOpeningRequest;
use App\Http\Requests\UpdateJobOpeningRequest;
use App\Http\Resources\JobOpeningResource;
use App\Models\JobOpening;
use App\Services\Recruitment\JobOpeningService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class JobOpeningController extends ApiController
{
    public function __construct(private readonly JobOpeningService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', JobOpening::class);
        $input = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'closed'])],
            'search' => ['nullable', 'string', 'max:180'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = JobOpening::query();
        if (isset($input['status'])) {
            $query->where('status', $input['status']);
        }
        if (isset($input['search']) && trim($input['search']) !== '') {
            $query->where(function (Builder $search) use ($input): void {
                $search->where('title', 'like', '%'.trim($input['search']).'%')
                    ->orWhere('location', 'like', '%'.trim($input['search']).'%');
            });
        }

        return JobOpeningResource::collection($query->orderByDesc('id')->paginate($input['per_page'] ?? 15)->withQueryString());
    }

    public function store(StoreJobOpeningRequest $request): JsonResponse
    {
        $opening = $this->service->create($request->user(), $request->validated());

        return (new JobOpeningResource($opening))->response()->setStatusCode(201);
    }

    public function show(JobOpening $jobOpening): JobOpeningResource
    {
        Gate::authorize('view', $jobOpening);

        return new JobOpeningResource($jobOpening);
    }

    public function update(UpdateJobOpeningRequest $request, JobOpening $jobOpening): JobOpeningResource
    {
        return new JobOpeningResource($this->service->update($jobOpening, $request->validated()));
    }
}
