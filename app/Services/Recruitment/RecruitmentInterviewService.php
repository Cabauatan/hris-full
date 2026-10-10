<?php

namespace App\Services\Recruitment;

use App\Events\InterviewEnded;
use App\Events\InterviewSignal;
use App\Models\Applicant;
use App\Models\JobApplication;
use App\Models\RecruitmentInterview;
use App\Models\User;
use App\Services\Notification\OutboundNotificationService;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RecruitmentInterviewService
{
    public function __construct(private readonly OutboundNotificationService $updates) {}

    /** @param array<string, mixed> $input */
    public function schedule(JobApplication $application, User $scheduler, array $input): RecruitmentInterview
    {
        return DB::transaction(function () use ($application, $scheduler, $input): RecruitmentInterview {
            $application = JobApplication::query()->lockForUpdate()->findOrFail($application->id);
            $this->ensureManager($scheduler);
            $this->ensureApproved($application);
            $scheduledAt = $this->parseSchedule($input['scheduled_at']);
            if ($scheduledAt->isPast()) {
                throw ValidationException::withMessages(['scheduled_at' => 'The interview must be scheduled in the future.']);
            }
            if (! in_array($input['round'], ['initial', 'final'], true)) {
                throw ValidationException::withMessages(['round' => 'Select an initial or final interview.']);
            }
            if (filter_var($input['duration_minutes'], FILTER_VALIDATE_INT) === false || $input['duration_minutes'] < 15 || $input['duration_minutes'] > 120) {
                throw ValidationException::withMessages(['duration_minutes' => 'The interview duration must be between 15 and 120 minutes.']);
            }
            if ($application->interviews()->where('round', $input['round'])->where('status', 'scheduled')->exists()) {
                throw new RuntimeException('This application already has a scheduled interview for this round.');
            }
            if ($input['round'] === 'final' && ! $application->interviews()->where('round', 'initial')->where('status', 'completed')->exists()) {
                throw new RuntimeException('Complete the initial interview before scheduling the final interview.');
            }

            $ids = $input['interviewer_ids'];
            if (! is_array($ids) || count($ids) < 1 || count($ids) > 4 || count(array_unique($ids)) !== count($ids)) {
                throw ValidationException::withMessages(['interviewer_ids' => 'Select between one and four distinct interviewers.']);
            }
            $interviewers = User::query()->whereIn('id', $ids)->lockForUpdate()->get();
            if ($interviewers->count() !== count($ids) || $interviewers->contains(fn (User $user): bool => ! $user->hasPermission('recruitment.manage'))) {
                throw ValidationException::withMessages(['interviewer_ids' => 'Every interviewer must be an active recruitment manager.']);
            }

            $interview = $application->interviews()->create([
                'uuid' => (string) Str::uuid(),
                'round' => $input['round'],
                'status' => 'scheduled',
                'scheduled_by' => $scheduler->id,
                'scheduled_at' => $scheduledAt,
                'duration_minutes' => $input['duration_minutes'],
                'private_notes' => $input['private_notes'] ?? null,
            ]);
            $interview->interviewers()->attach($interviewers->modelKeys());
            $this->queueInterviewUpdates($interview, $application->applicant, 'scheduled');

            return $interview;
        });
    }

    public function cancel(RecruitmentInterview $interview, User $actor, ?string $privateNotes = null): RecruitmentInterview
    {
        return $this->finish($interview, $actor, 'cancelled', $privateNotes);
    }

    public function complete(RecruitmentInterview $interview, User $actor, ?string $privateNotes = null): RecruitmentInterview
    {
        return $this->finish($interview, $actor, 'completed', $privateNotes);
    }

    /** @return array{interview: RecruitmentInterview, actor: array<string, mixed>, participants: list<array<string, mixed>>, ice_servers: array} */
    public function join(RecruitmentInterview $interview, User|Applicant $actor): array
    {
        return DB::transaction(function () use ($interview, $actor): array {
            [$application, $interview] = $this->lockInterview($interview);
            $actor = $this->freshParticipant($actor, $interview, $application);
            $this->ensureRoomOpen($interview, $application);
            if ($interview->started_at === null) {
                $interview->update(['started_at' => now()]);
            }

            return [
                'interview' => $interview,
                'actor' => $this->identity($actor),
                'participants' => $this->participants($interview),
                'ice_servers' => config('recruitment.ice_servers', []),
            ];
        });
    }

    /** @param array<string, mixed> $input */
    public function signal(RecruitmentInterview $interview, User|Applicant $actor, array $input): void
    {
        $event = DB::transaction(function () use ($interview, $actor, $input): InterviewSignal {
            [$application, $interview] = $this->lockInterview($interview);
            $actor = $this->freshParticipant($actor, $interview, $application);
            $this->ensureRoomOpen($interview, $application);
            $sender = $this->identity($actor);
            $target = $input['target_key'] ?? null;
            $type = $input['type'];
            $allowedTypes = ['ready', 'offer', 'answer', 'ice', 'leave'];
            if (! in_array($type, $allowedTypes, true)) {
                throw ValidationException::withMessages(['type' => 'Select a valid interview signal type.']);
            }
            if ($target !== null && ($target === $sender['key'] || ! in_array($target, array_column($this->participants($interview), 'key'), true))) {
                throw ValidationException::withMessages(['target_key' => 'Select another participant in this interview.']);
            }
            if (! in_array($type, ['ready', 'leave'], true) && $target === null) {
                throw ValidationException::withMessages(['target_key' => 'A recipient is required for this interview signal.']);
            }

            $payload = $this->signalPayload($type, $input['payload'] ?? []);
            $event = new InterviewSignal($interview->uuid, $type, $sender, $target, $payload);
            $wire = json_encode(['name' => 'interview.signal', 'channels' => ['private-Recruitment.Interview.'.$interview->uuid], 'data' => json_encode($event->broadcastWith(), JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR);
            if (strlen($wire) > 9500) {
                throw ValidationException::withMessages(['payload' => 'The interview signal is too large.']);
            }

            return $event;
        });

        event($event);
    }

    public function canJoin(RecruitmentInterview $interview, User|Applicant $actor): bool
    {
        $application = $interview->application;
        if ($application->status !== 'approved' || $interview->status !== 'scheduled' || ! $this->isParticipant($interview, $actor)) {
            return false;
        }
        $window = $this->window($interview);

        return now()->betweenIncluded($window['from'], $window['until']);
    }

    public function isParticipant(RecruitmentInterview $interview, User|Applicant $actor): bool
    {
        if ($actor instanceof Applicant) {
            return $actor->is_active && $interview->application->applicant_id === $actor->id;
        }

        $assigned = $interview->relationLoaded('interviewers')
            ? $interview->interviewers->contains(fn (User $interviewer): bool => $interviewer->id === $actor->id)
            : $interview->interviewers()->whereKey($actor->id)->exists();

        return $actor->hasPermission('recruitment.manage') && $assigned;
    }

    /** @return array{from: CarbonImmutable, until: CarbonImmutable} */
    public function window(RecruitmentInterview $interview): array
    {
        $scheduledAt = CarbonImmutable::instance($interview->scheduled_at);

        return [
            'from' => $scheduledAt->subMinutes(config('recruitment.early_join_minutes', 15)),
            'until' => $scheduledAt->addMinutes($interview->duration_minutes + config('recruitment.grace_minutes', 30)),
        ];
    }

    /** @return array{kind: string, id: int, key: string, name: string} */
    public function identity(User|Applicant $actor): array
    {
        $kind = $actor instanceof Applicant ? 'applicant' : 'user';
        $name = $actor instanceof Applicant ? trim($actor->first_name.' '.$actor->last_name) : $actor->name;

        return ['kind' => $kind, 'id' => $actor->id, 'key' => $kind.':'.$actor->id, 'name' => $name];
    }

    /** @return list<array{kind: string, id: int, key: string, name: string}> */
    public function participants(RecruitmentInterview $interview): array
    {
        $interview->loadMissing(['application.applicant', 'interviewers']);
        $participants = [$this->identity($interview->application->applicant)];
        foreach ($interview->interviewers as $interviewer) {
            if ($interviewer->hasPermission('recruitment.manage')) {
                $participants[] = $this->identity($interviewer);
            }
        }

        return $participants;
    }

    private function finish(RecruitmentInterview $interview, User $actor, string $status, ?string $privateNotes): RecruitmentInterview
    {
        $interview = DB::transaction(function () use ($interview, $actor, $status, $privateNotes): RecruitmentInterview {
            [$application, $interview] = $this->lockInterview($interview);
            $this->ensureManager($actor);
            $this->ensureApproved($application);
            if ($interview->status !== 'scheduled') {
                throw new RuntimeException('Only scheduled interviews can be completed or cancelled.');
            }
            $interview->update([
                'status' => $status,
                'completed_by' => $actor->id,
                'ended_at' => now(),
                'private_notes' => $privateNotes ?? $interview->private_notes,
            ]);
            if ($status === 'cancelled') {
                $this->queueInterviewUpdates($interview, $application->applicant, 'cancelled');
            }

            return $interview;
        });

        event(new InterviewEnded($interview->uuid, $status));

        return $interview;
    }

    private function queueInterviewUpdates(RecruitmentInterview $interview, Applicant $applicant, string $event): void
    {
        $eventId = 'hris/recruitment/recruitment_interview/'.$interview->id.'/'.$event;
        $version = $interview->scheduled_at->format('Y-m-d H:i:s');
        $this->updates->queue('recruitment_interview', $interview->id, $event, $applicant, $eventId, $version);
        foreach ($interview->interviewers as $interviewer) {
            if ($interviewer->hasPermission('recruitment.view') && $interviewer->hasPermission('recruitment.manage')) {
                $this->updates->queue('recruitment_interview', $interview->id, $event, $interviewer, $eventId, $version);
            }
        }
    }

    /** @return array{JobApplication, RecruitmentInterview} */
    private function lockInterview(RecruitmentInterview $interview): array
    {
        $application = JobApplication::query()->lockForUpdate()->findOrFail($interview->job_application_id);
        $interview = RecruitmentInterview::query()->lockForUpdate()->findOrFail($interview->id);
        abort_unless($interview->job_application_id === $application->id, 404);

        return [$application, $interview];
    }

    private function freshParticipant(User|Applicant $actor, RecruitmentInterview $interview, JobApplication $application): User|Applicant
    {
        $actor = $actor::query()->findOrFail($actor->id);
        if ($actor instanceof Applicant) {
            abort_unless($application->applicant_id === $actor->id, 404);
            abort_unless($actor->is_active, 403);
        } else {
            abort_unless($actor->hasPermission('recruitment.manage') && $interview->interviewers()->whereKey($actor->id)->exists(), 403);
        }

        return $actor;
    }

    private function ensureApproved(JobApplication $application): void
    {
        if ($application->status !== 'approved') {
            throw new RuntimeException('The application must be approved for this action.');
        }
    }

    private function ensureManager(User $actor): void
    {
        abort_unless(User::query()->findOrFail($actor->id)->hasPermission('recruitment.manage'), 403);
    }

    private function ensureRoomOpen(RecruitmentInterview $interview, JobApplication $application): void
    {
        $this->ensureApproved($application);
        if ($interview->status !== 'scheduled') {
            throw new RuntimeException('This interview room is closed.');
        }
        $window = $this->window($interview);
        if (! now()->betweenIncluded($window['from'], $window['until'])) {
            throw new RuntimeException('The interview room is outside its scheduled joining window.');
        }
    }

    public function parseSchedule(string $value): CarbonImmutable
    {
        if (! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/', $value)) {
            throw ValidationException::withMessages(['scheduled_at' => 'Use an ISO date and time with an explicit timezone offset.']);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw ValidationException::withMessages(['scheduled_at' => 'Use a valid ISO date and time with an explicit timezone offset.']);
        }

        return CarbonImmutable::instance($date)->setTimezone(config('app.timezone'));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function signalPayload(string $type, array $payload): array
    {
        if (in_array($type, ['ready', 'leave'], true)) {
            if ($payload !== []) {
                throw ValidationException::withMessages(['payload' => 'Ready and leave signals must have an empty payload.']);
            }

            return [];
        }
        if (in_array($type, ['offer', 'answer'], true)) {
            if (! isset($payload['sdp']) || ! is_string($payload['sdp']) || strlen($payload['sdp']) > 8192) {
                throw ValidationException::withMessages(['payload.sdp' => 'Provide an SDP string no larger than 8192 bytes.']);
            }

            return Arr::only($payload, ['sdp']);
        }

        if (! isset($payload['candidate']) || ! is_string($payload['candidate']) || strlen($payload['candidate']) > 4096) {
            throw ValidationException::withMessages(['payload.candidate' => 'Provide an ICE candidate no larger than 4096 bytes.']);
        }

        return Arr::only($payload, ['candidate', 'sdpMid', 'sdpMLineIndex', 'usernameFragment']);
    }
}
