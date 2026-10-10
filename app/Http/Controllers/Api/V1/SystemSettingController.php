<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreSystemSettingRequest;
use App\Http\Requests\UpdateSystemSettingRequest;
use App\Http\Resources\SystemSettingResource;
use App\Models\SystemSetting;
use App\Services\SystemSetting\SystemSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class SystemSettingController extends ApiController
{
    public function __construct(private SystemSettingService $settings) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', SystemSetting::class);
        $query = SystemSetting::query();

        return SystemSettingResource::collection($this->paginate($request, $query));
    }

    public function show(SystemSetting $systemSetting): SystemSettingResource
    {
        Gate::authorize('view', $systemSetting);

        return new SystemSettingResource($systemSetting);
    }

    public function store(StoreSystemSettingRequest $request): JsonResponse
    {
        $input = $request->validated();
        $systemSetting = $this->settings->create($request->user(), $input['group'], $input['key'], $input['value'], $input['type'], $input['description'] ?? null, (bool) ($input['is_public'] ?? false), (bool) ($input['is_editable'] ?? true));

        return (new SystemSettingResource($systemSetting->fresh()))->response()->setStatusCode(201);
    }

    public function update(UpdateSystemSettingRequest $request, SystemSetting $systemSetting): SystemSettingResource
    {
        $systemSetting = $this->settings->updateValue($systemSetting, $request->user(), $request->validated('value'));

        return new SystemSettingResource($systemSetting->fresh());
    }
}
