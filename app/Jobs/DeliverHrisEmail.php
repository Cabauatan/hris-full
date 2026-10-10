<?php

namespace App\Jobs;

use App\Services\Notification\EmailNotificationService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

#[Tries(4)]
#[Timeout(60)]
#[Backoff(10, 30, 60)]
class DeliverHrisEmail implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(public readonly string $deliveryId) {}

    public function handle(EmailNotificationService $emails): void
    {
        $emails->deliver($this->deliveryId);
    }

    public function failed(?Throwable $exception): void
    {
        app(EmailNotificationService::class)->failed($this->deliveryId);
    }
}
