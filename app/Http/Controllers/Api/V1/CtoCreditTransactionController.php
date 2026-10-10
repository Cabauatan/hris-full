<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\CtoCreditTransactionResource;
use App\Models\CtoCreditTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CtoCreditTransactionController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', CtoCreditTransaction::class);
        $query = CtoCreditTransaction::query();

        $query = $this->visibleEmployeeRecords($request, $query, $request->user()->hasPermission('employees.view'));

        return CtoCreditTransactionResource::collection($this->paginate($request, $query));
    }

    public function show(CtoCreditTransaction $ctoCreditTransaction): CtoCreditTransactionResource
    {
        Gate::authorize('view', $ctoCreditTransaction);

        return new CtoCreditTransactionResource($ctoCreditTransaction);
    }
}
