<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreHolidayRequest;
use App\Http\Requests\UpdateHolidayRequest;
use App\Http\Resources\HolidayResource;
use App\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class HolidayController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Holiday::class);
        $query = Holiday::query();

        return HolidayResource::collection($this->paginate($request, $query));
    }

    public function show(Holiday $holiday): HolidayResource
    {
        Gate::authorize('view', $holiday);

        return new HolidayResource($holiday);
    }

    public function store(StoreHolidayRequest $request): JsonResponse
    {
        $holiday = Holiday::query()->create($request->validated() + ['created_by_user_id' => $request->user()->id]);

        return (new HolidayResource($holiday->fresh()))->response()->setStatusCode(201);
    }

    public function update(UpdateHolidayRequest $request, Holiday $holiday): HolidayResource
    {
        $holiday->update($request->validated());

        return new HolidayResource($holiday->fresh());
    }
}
