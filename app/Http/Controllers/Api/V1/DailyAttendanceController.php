<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\DailyAttendanceResource;
use App\Models\DailyAttendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class DailyAttendanceController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', DailyAttendance::class);
        $query = DailyAttendance::query();

        $query = $this->visibleEmployeeRecords($request, $query, $request->user()->hasPermission('employees.view'));

        return DailyAttendanceResource::collection($this->paginate($request, $query));
    }

    public function show(DailyAttendance $dailyAttendance): DailyAttendanceResource
    {
        Gate::authorize('view', $dailyAttendance);

        return new DailyAttendanceResource($dailyAttendance);
    }
}
