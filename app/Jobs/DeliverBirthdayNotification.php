<?php

namespace App\Jobs;

use App\Services\Notification\BirthdayNotificationService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

#[Tries(4)]
#[Timeout(60)]
#[Backoff(10, 30, 60)]
class DeliverBirthdayNotification implements ShouldQueueAfterCommit
{
    use Queueable;

    /** @param list<int>|null $recipientIds */
    public function __construct(
        public readonly int $employeeId,
        public readonly string $birthdayDate,
        public readonly ?array $recipientIds = null,
    ) {}

    public function handle(BirthdayNotificationService $notifications): void
    {
        $notifications->deliver($this);
    }
}
