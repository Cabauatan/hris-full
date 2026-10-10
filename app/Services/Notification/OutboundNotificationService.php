<?php

namespace App\Services\Notification;

use App\Models\Applicant;
use App\Models\User;
use Illuminate\Support\Str;

class OutboundNotificationService
{
    public function __construct(
        private readonly EmailNotificationService $emails,
        private readonly TelegramNotificationService $telegram,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('hris_mail.enabled') || (bool) config('telegram.enabled');
    }

    public function queue(
        string $resourceType,
        int $resourceId,
        string $event,
        User|Applicant $recipient,
        ?string $eventId = null,
        ?string $sourceVersion = null,
    ): void {
        $eventId ??= (string) Str::uuid();
        $this->emails->queue($resourceType, $resourceId, $event, $recipient, $eventId, $sourceVersion);
        $this->telegram->queue($resourceType, $resourceId, $event, $recipient, $eventId, $sourceVersion);
    }
}
