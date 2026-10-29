<?php

namespace App\Services\Recruitment;

use App\Events\InterviewMessageSent;
use App\Models\Applicant;
use App\Models\JobApplication;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentInterviewMessage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RecruitmentInterviewMessageService
{
    public const MAX_BODY_BYTES = 2000;

    public function __construct(private readonly RecruitmentInterviewService $interviews) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{messages: Collection<int, RecruitmentInterviewMessage>, has_more: bool, can_send: bool}
     */
    public function history(RecruitmentInterview $interview, User|Applicant $actor, array $input = []): array
    {
        $input = Validator::make($input, [
            'after_id' => ['sometimes', 'integer', 'min:0', 'max:'.PHP_INT_MAX, 'prohibits:before_id'],
            'before_id' => ['sometimes', 'integer', 'min:1', 'max:'.PHP_INT_MAX, 'prohibits:after_id'],
        ])->validate();

        return DB::transaction(function () use ($interview, $actor, $input): array {
            [$interview, $actor] = $this->lockParticipant($interview, $actor);
            $after = array_key_exists('after_id', $input);
            $cursorField = $after ? 'after_id' : 'before_id';
            $cursor = isset($input[$cursorField]) ? (int) $input[$cursorField] : null;
            if ($cursor !== null && $cursor > 0 && ! $interview->messages()->whereKey($cursor)->exists()) {
                throw ValidationException::withMessages([$cursorField => 'This message cursor is unavailable for this interview.']);
            }

            $query = $interview->messages();
            if ($cursor !== null) {
                $query->where('id', $after ? '>' : '<', $cursor);
            }
            $messages = $query->orderBy('id', $after ? 'asc' : 'desc')->limit(51)->get();
            $hasMore = $messages->count() > 50;
            $messages = $messages->take(50);
            if (! $after) {
                $messages = $messages->reverse();
            }
            $messages = $messages->values();
            foreach ($messages as $message) {
                $message->setRelation('interview', $interview);
            }

            return [
                'messages' => $messages,
                'has_more' => $hasMore,
                'can_send' => $this->interviews->canJoin($interview, $actor),
            ];
        });
    }

    /** @param array<string, mixed> $input */
    public function send(RecruitmentInterview $interview, User|Applicant $actor, array $input): RecruitmentInterviewMessage
    {
        $input = Validator::make($input, [
            'body' => ['required', 'string', 'max:2000'],
            'client_message_id' => ['required', 'uuid'],
        ])->validate();
        $body = preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $input['body']);
        if ($body === null || $body === '' || strlen($body) > self::MAX_BODY_BYTES
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $body)) {
            throw ValidationException::withMessages(['body' => 'Enter a message of up to 2000 bytes without control characters.']);
        }
        $clientMessageId = Str::lower($input['client_message_id']);

        $message = DB::transaction(function () use ($interview, $actor, $body, $clientMessageId): RecruitmentInterviewMessage {
            [$interview, $actor] = $this->lockParticipant($interview, $actor);
            $sender = $this->interviews->identity($actor);
            $existing = $interview->messages()
                ->where('sender_kind', $sender['kind'])->where('sender_id', $sender['id'])
                ->where('client_message_id', $clientMessageId)->first();
            if ($existing !== null) {
                if ($existing->body !== $body) {
                    throw ValidationException::withMessages(['client_message_id' => 'This message identifier has already been used for another message.']);
                }

                return $existing->setRelation('interview', $interview);
            }
            if (! $this->interviews->canJoin($interview, $actor)) {
                throw new RuntimeException('Messages can only be sent while this interview room is open.');
            }

            $message = $interview->messages()->create([
                'sender_kind' => $sender['kind'],
                'sender_id' => $sender['id'],
                'sender_name' => Str::substr($sender['name'], 0, 255),
                'client_message_id' => $clientMessageId,
                'body' => $body,
            ]);

            return $message->setRelation('interview', $interview);
        });

        event(new InterviewMessageSent($message->interview->uuid, $message->id));

        return $message;
    }

    /** @return array{RecruitmentInterview, User|Applicant} */
    private function lockParticipant(RecruitmentInterview $interview, User|Applicant $actor): array
    {
        $application = JobApplication::query()->lockForUpdate()->findOrFail($interview->job_application_id);
        $interview = RecruitmentInterview::query()->lockForUpdate()->findOrFail($interview->id);
        abort_unless($interview->job_application_id === $application->id, 404);
        $interview->setRelation('application', $application);
        $actor = $actor::query()->findOrFail($actor->id);
        Gate::forUser($actor)->authorize('readMessages', $interview);

        return [$interview, $actor];
    }
}
