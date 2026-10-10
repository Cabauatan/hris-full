<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreWorkScheduleDayRequest;
use App\Http\Requests\UpdateWorkScheduleDayRequest;
use App\Http\Resources\WorkScheduleDayResource;
use App\Models\WorkScheduleDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class WorkScheduleDayController extends ApiController
{
    private const RELATIONS = [
        'workSchedule:id,code,name',
        'shift:id,code,name,start_time,end_time,is_overnight',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', WorkScheduleDay::class);
        $query = WorkScheduleDay::query()->with(self::RELATIONS);

        return WorkScheduleDayResource::collection($this->paginate($request, $query));
    }

    public function show(WorkScheduleDay $workScheduleDay): WorkScheduleDayResource
    {
        Gate::authorize('view', $workScheduleDay);

        return new WorkScheduleDayResource($workScheduleDay->load(self::RELATIONS));
    }

    public function store(StoreWorkScheduleDayRequest $request): JsonResponse
    {
        $workScheduleDay = WorkScheduleDay::query()->create($request->validated());

        return (new WorkScheduleDayResource($workScheduleDay->fresh(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function update(UpdateWorkScheduleDayRequest $request, WorkScheduleDay $workScheduleDay): WorkScheduleDayResource
    {
        $workScheduleDay->update($request->validated());

        return new WorkScheduleDayResource($workScheduleDay->fresh(self::RELATIONS));
    }
}
