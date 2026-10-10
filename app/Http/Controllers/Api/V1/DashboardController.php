<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\GetDashboardRequest;
use App\Http\Resources\WorkspaceResource;
use App\Services\Workspace\DashboardService;

class DashboardController extends ApiController
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(GetDashboardRequest $request, DashboardService $service): WorkspaceResource
    {
        return new WorkspaceResource($service->get($request->user(), $request->validated()));
    }
}
