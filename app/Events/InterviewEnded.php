<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class InterviewEnded implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(public readonly string $interviewUuid, public readonly string $status) {}

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
        return 'interview.ended';
    }

    public function broadcastWith(): array
    {
        return ['interview_uuid' => $this->interviewUuid, 'status' => $this->status];
    }
}
