<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class InterviewMessageSent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly string $interviewUuid, public readonly int $messageId) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('Recruitment.Interview.'.$this->interviewUuid)];
    }

    public function broadcastAs(): string
    {
        return 'interview.message';
    }

    /** @return array{interview_uuid: string, message_id: int} */
    public function broadcastWith(): array
    {
        return ['interview_uuid' => $this->interviewUuid, 'message_id' => $this->messageId];
    }
}
