<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\IndexRecruitmentInterviewMessageRequest;
use App\Http\Requests\StoreRecruitmentInterviewMessageRequest;
use App\Http\Resources\RecruitmentInterviewMessageResource;
use App\Models\RecruitmentInterview;
use App\Services\Recruitment\RecruitmentInterviewMessageService;
use Illuminate\Http\JsonResponse;

class RecruitmentInterviewMessageController extends ApiController
{
    public function __construct(private readonly RecruitmentInterviewMessageService $service) {}

    public function index(IndexRecruitmentInterviewMessageRequest $request, RecruitmentInterview $recruitmentInterview): JsonResponse
    {
        $history = $this->service->history($recruitmentInterview, $request->user(), $request->validated());

        return response()->json([
            'data' => RecruitmentInterviewMessageResource::collection($history['messages'])->resolve($request),
            'meta' => ['has_more' => $history['has_more'], 'can_send' => $history['can_send']],
        ]);
    }

    public function store(StoreRecruitmentInterviewMessageRequest $request, RecruitmentInterview $recruitmentInterview): JsonResponse
    {
        $message = $this->service->send($recruitmentInterview, $request->user(), $request->validated());

        return (new RecruitmentInterviewMessageResource($message))->response()
            ->setStatusCode($message->wasRecentlyCreated ? 201 : 200);
    }
}
