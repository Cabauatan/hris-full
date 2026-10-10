<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AuditLog::class);
        $query = AuditLog::query();

        return AuditLogResource::collection($this->paginate($request, $query));
    }

    public function show(AuditLog $auditLog): AuditLogResource
    {
        Gate::authorize('view', $auditLog);

        return new AuditLogResource($auditLog);
    }
}
