<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\LeaveCreditTransactionResource;
use App\Models\LeaveCreditTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class LeaveCreditTransactionController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', LeaveCreditTransaction::class);
        $query = LeaveCreditTransaction::query();

        $query = $this->visibleEmployeeRecords($request, $query, $request->user()->hasPermission('employees.view'));

        return LeaveCreditTransactionResource::collection($this->paginate($request, $query));
    }

    public function show(LeaveCreditTransaction $leaveCreditTransaction): LeaveCreditTransactionResource
    {
        Gate::authorize('view', $leaveCreditTransaction);

        return new LeaveCreditTransactionResource($leaveCreditTransaction);
    }
}
