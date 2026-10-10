<?php

namespace App\Notifications;

use App\Services\Notification\IdempotentDatabaseChannel;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class HrisNotification extends Notification
{
    /** @param array{resource_type: string, resource_id: int, event: string, occurred_at: string} $data */
    public function __construct(private readonly array $data) {}

    /** @return array<class-string> */
    public function via(object $notifiable): array
    {
        return [IdempotentDatabaseChannel::class];
    }

    /** @return array{resource_type: string, resource_id: int, event: string, occurred_at: string} */
    public function toDatabase(object $notifiable): array
    {
        return $this->data;
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage([]))->onQueue('notifications');
    }
}
