<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StorePositionRequest;
use App\Http\Requests\UpdatePositionRequest;
use App\Http\Resources\PositionResource;
use App\Models\Position;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class PositionController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Position::class);
        $query = Position::query();

        return PositionResource::collection($this->paginate($request, $query));
    }

    public function show(Position $position): PositionResource
    {
        Gate::authorize('view', $position);

        return new PositionResource($position);
    }

    public function store(StorePositionRequest $request): JsonResponse
    {
        $position = Position::query()->create($request->validated());

        return (new PositionResource($position->fresh()))->response()->setStatusCode(201);
    }

    public function update(UpdatePositionRequest $request, Position $position): PositionResource
    {
        $position->update($request->validated());

        return new PositionResource($position->fresh());
    }
}
