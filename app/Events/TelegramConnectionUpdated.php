<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class TelegramConnectionUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly string $recipientType, public readonly int $recipientId) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $model = match ($this->recipientType) {
            'user' => 'User',
            'applicant' => 'Applicant',
        };

        return [new PrivateChannel('App.Models.'.$model.'.'.$this->recipientId)];
    }

    public function broadcastAs(): string
    {
        return 'telegram.connection.updated';
    }

    public function broadcastQueue(): string
    {
        return 'notifications';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [];
    }
}
