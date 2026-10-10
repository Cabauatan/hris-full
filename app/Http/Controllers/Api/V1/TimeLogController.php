<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreTimeLogRequest;
use App\Http\Requests\UpdateTimeLogRequest;
use App\Http\Resources\TimeLogResource;
use App\Models\TimeLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class TimeLogController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', TimeLog::class);
        $query = TimeLog::query();

        $query = $this->visibleEmployeeRecords($request, $query, $request->user()->hasPermission('employees.view'));

        return TimeLogResource::collection($this->paginate($request, $query));
    }

    public function show(TimeLog $timeLog): TimeLogResource
    {
        Gate::authorize('view', $timeLog);

        return new TimeLogResource($timeLog);
    }

    public function store(StoreTimeLogRequest $request): JsonResponse
    {
        $timeLog = TimeLog::query()->create($request->validated() + ['created_by_user_id' => $request->user()->id]);

        return (new TimeLogResource($timeLog->fresh()))->response()->setStatusCode(201);
    }

    public function update(UpdateTimeLogRequest $request, TimeLog $timeLog): TimeLogResource
    {
        $timeLog->update($request->validated());

        return new TimeLogResource($timeLog->fresh());
    }
}
