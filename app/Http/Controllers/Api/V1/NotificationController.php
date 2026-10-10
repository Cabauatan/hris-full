<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\IndexNotificationRequest;
use App\Http\Requests\ReadAllNotificationsRequest;
use App\Http\Requests\ReadNotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Services\Notification\NotificationInboxService;
use App\Services\Notification\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends ApiController
{
    public function __construct(
        private readonly NotificationInboxService $inbox,
        private readonly NotificationService $notifications,
    ) {}

    public function index(IndexNotificationRequest $request): AnonymousResourceCollection
    {
        $records = $this->inbox->paginate($request->user(), $request->validated());
        $this->notifications->preparePresentations($request->user(), $records->items());
        $records->through(fn (DatabaseNotification $notification): array => $this->notifications->present($request->user(), $notification));

        return NotificationResource::collection($records)->additional([
            'meta' => ['unread_count' => $this->inbox->unreadCount($request->user())],
        ]);
    }

    public function unreadCount(IndexNotificationRequest $request): JsonResponse
    {
        return response()->json(['data' => ['unread_count' => $this->inbox->unreadCount($request->user())]]);
    }

    public function read(ReadNotificationRequest $request): NotificationResource
    {
        $notification = $this->inbox->markRead($request->user(), $request->route('notification'));
        $this->notifications->preparePresentations($request->user(), [$notification]);

        return (new NotificationResource($this->notifications->present($request->user(), $notification)))->additional([
            'meta' => ['unread_count' => $this->inbox->unreadCount($request->user())],
        ]);
    }

    public function readAll(ReadAllNotificationsRequest $request): JsonResponse
    {
        $updated = $this->inbox->markAllRead($request->user());

        return response()->json(['data' => [
            'updated' => $updated,
            'unread_count' => $this->inbox->unreadCount($request->user()),
        ]]);
    }
}
