<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\GetCalendarRequest;
use App\Http\Resources\WorkspaceResource;
use App\Services\Workspace\CalendarService;

class CalendarController extends ApiController
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(GetCalendarRequest $request, CalendarService $service): WorkspaceResource
    {
        return new WorkspaceResource($service->get($request->user(), $request->validated()));
    }
}
