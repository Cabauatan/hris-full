<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class AccountSessionChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly string $guard, public readonly int $accountId) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $model = match ($this->guard) {
            'web' => 'User',
            'applicant' => 'Applicant',
        };

        return [new PrivateChannel('App.Models.'.$model.'.'.$this->accountId)];
    }

    public function broadcastAs(): string
    {
        return 'account.session.changed';
    }

    public function broadcastQueue(): string
    {
        return 'notifications';
    }

    /** @return array{guard: string} */
    public function broadcastWith(): array
    {
        return ['guard' => $this->guard];
    }
}
