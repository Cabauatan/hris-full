<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreWorkScheduleRequest;
use App\Http\Requests\UpdateWorkScheduleRequest;
use App\Http\Resources\WorkScheduleResource;
use App\Models\WorkSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class WorkScheduleController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', WorkSchedule::class);
        $query = WorkSchedule::query();

        return WorkScheduleResource::collection($this->paginate($request, $query));
    }

    public function show(WorkSchedule $workSchedule): WorkScheduleResource
    {
        Gate::authorize('view', $workSchedule);

        return new WorkScheduleResource($workSchedule);
    }

    public function store(StoreWorkScheduleRequest $request): JsonResponse
    {
        $workSchedule = WorkSchedule::query()->create($request->validated());

        return (new WorkScheduleResource($workSchedule->fresh()))->response()->setStatusCode(201);
    }

    public function update(UpdateWorkScheduleRequest $request, WorkSchedule $workSchedule): WorkScheduleResource
    {
        $workSchedule->update($request->validated());

        return new WorkScheduleResource($workSchedule->fresh());
    }
}
