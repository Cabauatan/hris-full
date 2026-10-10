<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreDivisionRequest;
use App\Http\Requests\UpdateDivisionRequest;
use App\Http\Resources\DivisionResource;
use App\Models\Division;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class DivisionController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Division::class);
        $query = Division::query();

        return DivisionResource::collection($this->paginate($request, $query));
    }

    public function show(Division $division): DivisionResource
    {
        Gate::authorize('view', $division);

        return new DivisionResource($division);
    }

    public function store(StoreDivisionRequest $request): JsonResponse
    {
        $division = Division::query()->create($request->validated());

        return (new DivisionResource($division->fresh()))->response()->setStatusCode(201);
    }

    public function update(UpdateDivisionRequest $request, Division $division): DivisionResource
    {
        $division->update($request->validated());

        return new DivisionResource($division->fresh());
    }
}
