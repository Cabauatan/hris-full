<?php

namespace App\Models;

use Database\Factories\RecruitmentInterviewMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecruitmentInterviewMessage extends Model
{
    /** @use HasFactory<RecruitmentInterviewMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'recruitment_interview_id', 'sender_kind', 'sender_id', 'sender_name',
        'client_message_id', 'body',
    ];

    protected function casts(): array
    {
        return ['sender_id' => 'integer'];
    }

    public function interview(): BelongsTo
    {
        return $this->belongsTo(RecruitmentInterview::class, 'recruitment_interview_id');
    }

    /** @return array<string, mixed> */
    public function toChatArray(string $interviewUuid): array
    {
        return [
            'id' => $this->id,
            'interview_uuid' => $interviewUuid,
            'body' => $this->body,
            'client_message_id' => $this->client_message_id,
            'sender' => [
                'kind' => $this->sender_kind,
                'id' => $this->sender_id,
                'key' => $this->sender_kind.':'.$this->sender_id,
                'name' => $this->sender_name,
            ],
            'created_at' => $this->created_at->toAtomString(),
        ];
    }
}
