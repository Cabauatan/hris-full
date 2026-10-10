<?php

namespace App\Jobs;

use App\Services\Notification\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

#[Tries(4)]
#[Timeout(60)]
#[Backoff(10, 30, 60)]
class DeliverHrisNotification implements ShouldQueueAfterCommit
{
    use Queueable;

    /** @param list<int>|null $recipientIds */
    public function __construct(
        public readonly string $resourceType,
        public readonly int $resourceId,
        public readonly string $event,
        public readonly string $eventId,
        public readonly string $occurredAt,
        public readonly ?string $publicationVersion = null,
        public readonly ?array $recipientIds = null,
    ) {}

    public function handle(NotificationService $notifications): void
    {
        $notifications->deliver($this);
    }
}
