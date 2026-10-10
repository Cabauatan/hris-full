<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class InterviewSignal implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly string $interviewUuid,
        public readonly string $type,
        public readonly array $sender,
        public readonly ?string $targetKey,
        public readonly array $payload,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('Recruitment.Interview.'.$this->interviewUuid),
        ];
    }

    public function broadcastAs(): string
    {
        return 'interview.signal';
    }

    public function broadcastWith(): array
    {
        return [
            'interview_uuid' => $this->interviewUuid,
            'type' => $this->type,
            'sender' => $this->sender,
            'target_key' => $this->targetKey,
            'payload' => $this->payload,
        ];
    }
}
