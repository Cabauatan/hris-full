<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\ApproveJobApplicationRequest;
use App\Http\Requests\AssignRecruitmentExamRequest;
use App\Http\Requests\HireJobApplicationRequest;
use App\Http\Requests\RejectJobApplicationRequest;
use App\Http\Resources\ApplicantExamAttemptResource;
use App\Http\Resources\JobApplicationResource;
use App\Models\JobApplication;
use App\Models\RecruitmentExam;
use App\Services\Recruitment\RecruitmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class JobApplicationController extends ApiController
{
    public function __construct(private readonly RecruitmentService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', JobApplication::class);
        $input = $request->validate([
            'status' => ['sometimes', Rule::in(['pending', 'approved', 'rejected', 'hired'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:180'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = JobApplication::query()
            ->with(['applicant', 'attempts.exam', 'attempts.application.applicant'])
            ->withCount([
                'attempts as ungraded_attempts_count' => fn (Builder $attempts): Builder => $attempts->where('status', '!=', 'graded'),
                'interviews as unfinished_interviews_count' => fn (Builder $interviews): Builder => $interviews->whereNotIn('status', ['completed', 'cancelled']),
            ]);

        if (isset($input['status'])) {
            $query->where('status', $input['status']);
        }
        if (! empty($input['search'])) {
            $term = '%'.$input['search'].'%';
            $query->where(function (Builder $applications) use ($term): void {
                $applications->where('desired_position', 'like', $term)
                    ->orWhereHas('applicant', function (Builder $applicants) use ($term): void {
                        $applicants->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)->orWhere('email', 'like', $term);
                    });
            });
        }

        return JobApplicationResource::collection($query->orderByDesc('id')->paginate($input['per_page'] ?? 15)->withQueryString());
    }

    public function show(JobApplication $jobApplication): JobApplicationResource
    {
        Gate::authorize('view', $jobApplication);

        return $this->resource($jobApplication);
    }

    public function approve(ApproveJobApplicationRequest $request, JobApplication $jobApplication): JobApplicationResource
    {
        return $this->resource($this->service->approve($jobApplication, $request->user(), $request->validated('remarks')));
    }

    public function reject(RejectJobApplicationRequest $request, JobApplication $jobApplication): JobApplicationResource
    {
        return $this->resource($this->service->reject($jobApplication, $request->user(), $request->validated('remarks')));
    }

    public function hire(HireJobApplicationRequest $request, JobApplication $jobApplication): JobApplicationResource
    {
        return $this->resource($this->service->hire($jobApplication, $request->user(), $request->validated('start_date'), $request->validated('remarks')));
    }

    public function assign(AssignRecruitmentExamRequest $request, JobApplication $jobApplication): JsonResponse
    {
        $exam = RecruitmentExam::query()->findOrFail($request->validated('recruitment_exam_id'));
        $attempt = $this->service->assign($jobApplication, $exam, $request->user());

        return (new ApplicantExamAttemptResource($attempt->load(['exam', 'application.applicant'])))->response()->setStatusCode(201);
    }

    private function resource(JobApplication $application): JobApplicationResource
    {
        return new JobApplicationResource($application
            ->load(['applicant', 'attempts.exam', 'attempts.application.applicant'])
            ->loadCount([
                'attempts as ungraded_attempts_count' => fn (Builder $attempts): Builder => $attempts->where('status', '!=', 'graded'),
                'interviews as unfinished_interviews_count' => fn (Builder $interviews): Builder => $interviews->whereNotIn('status', ['completed', 'cancelled']),
            ]));
    }
}
