<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreShiftRequest;
use App\Http\Requests\UpdateShiftRequest;
use App\Http\Resources\ShiftResource;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ShiftController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Shift::class);
        $query = Shift::query();

        return ShiftResource::collection($this->paginate($request, $query));
    }

    public function show(Shift $shift): ShiftResource
    {
        Gate::authorize('view', $shift);

        return new ShiftResource($shift);
    }

    public function store(StoreShiftRequest $request): JsonResponse
    {
        $shift = Shift::query()->create($request->validated());

        return (new ShiftResource($shift->fresh()))->response()->setStatusCode(201);
    }

    public function update(UpdateShiftRequest $request, Shift $shift): ShiftResource
    {
        $shift->update($request->validated());

        return new ShiftResource($shift->fresh());
    }
}
