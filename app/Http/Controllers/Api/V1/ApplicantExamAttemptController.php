<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\GradeApplicantExamRequest;
use App\Http\Requests\SubmitApplicantExamRequest;
use App\Http\Resources\ApplicantExamAttemptResource;
use App\Models\ApplicantExamAttempt;
use App\Services\Recruitment\RecruitmentService;
use Illuminate\Support\Facades\Gate;

class ApplicantExamAttemptController extends ApiController
{
    public function __construct(private readonly RecruitmentService $service) {}

    public function show(ApplicantExamAttempt $applicantExamAttempt): ApplicantExamAttemptResource
    {
        Gate::authorize('view', $applicantExamAttempt);

        return new ApplicantExamAttemptResource($applicantExamAttempt->load(['exam', 'application.applicant']));
    }

    public function submit(SubmitApplicantExamRequest $request, ApplicantExamAttempt $applicantExamAttempt): ApplicantExamAttemptResource
    {
        return new ApplicantExamAttemptResource($this->service->submit($applicantExamAttempt, $request->user(), $request->validated('answers'))
            ->load(['exam', 'application.applicant']));
    }

    public function grade(GradeApplicantExamRequest $request, ApplicantExamAttempt $applicantExamAttempt): ApplicantExamAttemptResource
    {
        return new ApplicantExamAttemptResource($this->service->grade($applicantExamAttempt, $request->user(), $request->validated('grades'))
            ->load(['exam', 'application.applicant']));
    }
}
