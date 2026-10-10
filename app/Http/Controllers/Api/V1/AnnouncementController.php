<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\CancelAnnouncementScheduleRequest;
use App\Http\Requests\DeactivateAnnouncementRequest;
use App\Http\Requests\PublishAnnouncementRequest;
use App\Http\Requests\ReactivateAnnouncementRequest;
use App\Http\Requests\RescheduleAnnouncementRequest;
use App\Http\Requests\ScheduleAnnouncementRequest;
use App\Http\Requests\StoreAnnouncementRequest;
use App\Http\Requests\UpdateAnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Services\Announcement\AnnouncementService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AnnouncementController extends ApiController
{
    public function __construct(private AnnouncementService $announcements) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Announcement::class);
        $query = Announcement::query();

        if (! $request->user()->hasPermission('announcements.manage')) {
            $query->where('is_active', true)->whereNotNull('published_at')->where('published_at', '<=', now())
                ->where(fn (Builder $query): Builder => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        }

        return AnnouncementResource::collection($this->paginate($request, $query));
    }

    public function show(Announcement $announcement): AnnouncementResource
    {
        Gate::authorize('view', $announcement);

        return new AnnouncementResource($announcement);
    }

    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        $input = $request->validated();
        $announcement = $this->announcements->createDraft($request->user(), $input['title'], $input['content'], $input['priority'] ?? 'normal', (bool) ($input['show_on_dashboard'] ?? true), $input['attachment_path'] ?? null);

        return (new AnnouncementResource($announcement->fresh()))->response()->setStatusCode(201);
    }

    public function update(UpdateAnnouncementRequest $request, Announcement $announcement): AnnouncementResource
    {
        $input = $request->validated();
        $announcement = $this->announcements->update($announcement, $input['title'], $input['content'], $input['priority'], (bool) $input['show_on_dashboard'], array_key_exists('attachment_path', $input) ? $input['attachment_path'] : $announcement->attachment_path);

        return new AnnouncementResource($announcement->fresh());
    }

    public function dashboard(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Announcement::class);

        return AnnouncementResource::collection($this->announcements->getDashboardAnnouncements());
    }

    public function publish(PublishAnnouncementRequest $request, Announcement $announcement): AnnouncementResource
    {
        return new AnnouncementResource($this->announcements->publish($announcement, $request->validated('expires_at') === null ? null : CarbonImmutable::parse($request->validated('expires_at'))));
    }

    public function schedule(ScheduleAnnouncementRequest $request, Announcement $announcement): AnnouncementResource
    {
        return new AnnouncementResource($this->announcements->schedule($announcement, CarbonImmutable::parse($request->validated('scheduled_at')), $request->validated('expires_at') === null ? null : CarbonImmutable::parse($request->validated('expires_at'))));
    }

    public function reschedule(RescheduleAnnouncementRequest $request, Announcement $announcement): AnnouncementResource
    {
        return new AnnouncementResource($this->announcements->reschedule($announcement, CarbonImmutable::parse($request->validated('scheduled_at')), $request->validated('expires_at') === null ? null : CarbonImmutable::parse($request->validated('expires_at'))));
    }

    public function cancelSchedule(CancelAnnouncementScheduleRequest $request, Announcement $announcement): AnnouncementResource
    {
        return new AnnouncementResource($this->announcements->cancelSchedule($announcement));
    }

    public function deactivate(DeactivateAnnouncementRequest $request, Announcement $announcement): AnnouncementResource
    {
        return new AnnouncementResource($this->announcements->deactivate($announcement));
    }

    public function reactivate(ReactivateAnnouncementRequest $request, Announcement $announcement): AnnouncementResource
    {
        return new AnnouncementResource($this->announcements->reactivate($announcement));
    }
}
