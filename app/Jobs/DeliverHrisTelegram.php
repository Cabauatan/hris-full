<?php

namespace App\Jobs;

use App\Exceptions\TelegramRequestException;
use App\Services\Notification\TelegramNotificationService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

#[Tries(4)]
#[Timeout(60)]
#[Backoff(10, 30, 60)]
class DeliverHrisTelegram implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(public readonly string $deliveryId) {}

    public function handle(TelegramNotificationService $telegram): void
    {
        try {
            $telegram->deliver($this->deliveryId);
        } catch (TelegramRequestException $exception) {
            if ($exception->errorCode !== 429) {
                throw $exception;
            }
            $this->release($exception->retryAfter ?? 30);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(TelegramNotificationService::class)->failed($this->deliveryId);
    }
}
