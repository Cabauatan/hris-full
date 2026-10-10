<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\AuthorizeInterviewBroadcastRequest;
use App\Http\Requests\CancelRecruitmentInterviewRequest;
use App\Http\Requests\CompleteRecruitmentInterviewRequest;
use App\Http\Requests\JoinRecruitmentInterviewRequest;
use App\Http\Requests\SignalRecruitmentInterviewRequest;
use App\Http\Requests\StoreRecruitmentInterviewRequest;
use App\Http\Resources\RecruitmentInterviewResource;
use App\Models\JobApplication;
use App\Models\RecruitmentInterview;
use App\Models\User;
use App\Services\Recruitment\RecruitmentInterviewService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

class RecruitmentInterviewController extends ApiController
{
    public function __construct(private readonly RecruitmentInterviewService $service) {}

    public function index(Request $request, JobApplication $jobApplication): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [RecruitmentInterview::class, $jobApplication]);

        return RecruitmentInterviewResource::collection($jobApplication->interviews()
            ->with(['application', 'interviewers:id,name,is_active', 'scheduler:id,name'])
            ->orderBy('scheduled_at')->orderBy('id')->get());
    }

    public function show(RecruitmentInterview $recruitmentInterview): RecruitmentInterviewResource
    {
        Gate::authorize('view', $recruitmentInterview);

        return $this->resource($recruitmentInterview);
    }

    public function store(StoreRecruitmentInterviewRequest $request, JobApplication $jobApplication): JsonResponse
    {
        return $this->resource($this->service->schedule($jobApplication, $request->user(), $request->validated()))
            ->response()->setStatusCode(201);
    }

    public function cancel(CancelRecruitmentInterviewRequest $request, RecruitmentInterview $recruitmentInterview): RecruitmentInterviewResource
    {
        return $this->resource($this->service->cancel($recruitmentInterview, $request->user(), $request->validated('private_notes')));
    }

    public function complete(CompleteRecruitmentInterviewRequest $request, RecruitmentInterview $recruitmentInterview): RecruitmentInterviewResource
    {
        return $this->resource($this->service->complete($recruitmentInterview, $request->user(), $request->validated('private_notes')));
    }

    public function join(JoinRecruitmentInterviewRequest $request, RecruitmentInterview $recruitmentInterview): JsonResponse
    {
        $room = $this->service->join($recruitmentInterview, $request->user());
        $room['interview'] = $this->resource($room['interview'])->resolve($request);

        return response()->json(['data' => $room]);
    }

    public function signal(SignalRecruitmentInterviewRequest $request, RecruitmentInterview $recruitmentInterview): JsonResponse
    {
        $this->service->signal($recruitmentInterview, $request->user(), $request->validated());

        return response()->json(['message' => 'Interview signal sent.']);
    }

    public function authorizeBroadcast(AuthorizeInterviewBroadcastRequest $request): JsonResponse
    {
        $channel = $request->validated('channel_name');
        $uuid = substr($channel, strlen('private-Recruitment.Interview.'));
        $interview = RecruitmentInterview::query()->where('uuid', $uuid)->firstOrFail();
        Gate::forUser($request->user())->authorize('participate', $interview);

        return response()->json(Broadcast::auth($request));
    }

    public function interviewers(Request $request): JsonResponse
    {
        abort_unless($request->user() instanceof User && $request->user()->hasPermission('recruitment.view'), 403);
        $permission = fn (Builder $permissions): Builder => $permissions->where('permissions.code', 'recruitment.manage')->where('permissions.is_active', true);
        $users = User::query()->where('is_active', true)
            ->where(fn (Builder $users): Builder => $users->whereHas('permissions', $permission)
                ->orWhereHas('roles', fn (Builder $roles): Builder => $roles->where('roles.is_active', true)->whereHas('permissions', $permission)))
            ->orderBy('name')->orderBy('id')->get(['id', 'name']);

        return response()->json(['data' => $users->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->all()]);
    }

    private function resource(RecruitmentInterview $interview): RecruitmentInterviewResource
    {
        return new RecruitmentInterviewResource($interview->load(['application', 'interviewers:id,name,is_active', 'scheduler:id,name']));
    }
}
