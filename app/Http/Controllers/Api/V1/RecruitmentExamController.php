<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreRecruitmentExamRequest;
use App\Http\Resources\RecruitmentExamResource;
use App\Models\RecruitmentExam;
use App\Services\Recruitment\RecruitmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class RecruitmentExamController extends ApiController
{
    public function __construct(private readonly RecruitmentService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', RecruitmentExam::class);

        return RecruitmentExamResource::collection($this->paginate($request, RecruitmentExam::query()));
    }

    public function store(StoreRecruitmentExamRequest $request): JsonResponse
    {
        $exam = $this->service->createExam($request->user(), $request->validated());

        return (new RecruitmentExamResource($exam))->response()->setStatusCode(201);
    }

    public function show(RecruitmentExam $recruitmentExam): RecruitmentExamResource
    {
        Gate::authorize('view', $recruitmentExam);

        return new RecruitmentExamResource($recruitmentExam);
    }
}
