<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\PayrollEntryItemResource;
use App\Models\PayrollEntryItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class PayrollEntryItemController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PayrollEntryItem::class);
        $query = PayrollEntryItem::query();

        return PayrollEntryItemResource::collection($this->paginate($request, $query));
    }

    public function show(PayrollEntryItem $payrollEntryItem): PayrollEntryItemResource
    {
        Gate::authorize('view', $payrollEntryItem);

        return new PayrollEntryItemResource($payrollEntryItem);
    }
}
