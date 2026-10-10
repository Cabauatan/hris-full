<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreJobApplicationRequest;
use App\Http\Resources\JobApplicationResource;
use App\Models\JobApplication;
use App\Services\Recruitment\RecruitmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ApplicantApplicationController extends ApiController
{
    public function __construct(private readonly RecruitmentService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', JobApplication::class);
        $query = JobApplication::query()->where('applicant_id', $request->user()->id)
            ->with(['applicant', 'attempts.exam', 'attempts.application.applicant'])
            ->withCount([
                'attempts as ungraded_attempts_count' => fn (Builder $attempts): Builder => $attempts->where('status', '!=', 'graded'),
                'interviews as unfinished_interviews_count' => fn (Builder $interviews): Builder => $interviews->whereNotIn('status', ['completed', 'cancelled']),
            ]);

        return JobApplicationResource::collection($this->paginate($request, $query));
    }

    public function store(StoreJobApplicationRequest $request): JsonResponse
    {
        $application = $this->service->apply($request->user(), $request->validated());

        return (new JobApplicationResource($application->load(['applicant', 'attempts'])))->response()->setStatusCode(201);
    }

    public function show(JobApplication $jobApplication): JobApplicationResource
    {
        Gate::authorize('view', $jobApplication);

        return new JobApplicationResource($jobApplication
            ->load(['applicant', 'attempts.exam', 'attempts.application.applicant'])
            ->loadCount([
                'attempts as ungraded_attempts_count' => fn (Builder $attempts): Builder => $attempts->where('status', '!=', 'graded'),
                'interviews as unfinished_interviews_count' => fn (Builder $interviews): Builder => $interviews->whereNotIn('status', ['completed', 'cancelled']),
            ]));
    }
}
