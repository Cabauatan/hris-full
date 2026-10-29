<?php

namespace Tests\Feature;

use App\Events\InterviewMessageSent;
use App\Models\Applicant;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentInterviewMessage;
use App\Models\User;
use App\Services\Recruitment\RecruitmentInterviewMessageService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\FormRequestTestCase;

class RecruitmentInterviewMessageApiTest extends FormRequestTestCase
{
    #[DataProvider('protectedEndpoints')]
    public function test_guest_needs_the_correct_guard_to_read_or_send_messages(string $method, string $prefix): void
    {
        $this->json($method, '/api/v1/'.$prefix.'/interviews/1/messages')->assertUnauthorized();
    }

    public function test_assigned_hr_and_applicant_exchange_plain_text_without_a_camera_or_spoofed_attribution(): void
    {
        $this->freezeTime();
        [$interview, $staff, $applicant] = $this->room();
        $this->assertSame($staff->id, $applicant->id);
        Event::fake([InterviewMessageSent::class]);
        $clientId = (string) Str::uuid();
        $body = '<img src=x onerror=alert(1)> Hello';
        $response = $this->actingAs($staff, 'web')->postJson($this->url($interview), [
            'body' => '  '.$body.'  ', 'client_message_id' => $clientId,
            'sender_kind' => 'applicant', 'sender_id' => $applicant->id,
            'sender_name' => 'Spoofed', 'recruitment_interview_id' => 999,
        ])->assertCreated()->assertJsonPath('data.body', $body)
            ->assertJsonPath('data.sender.key', 'user:'.$staff->id)
            ->assertJsonPath('data.sender.name', $staff->name)
            ->assertJsonPath('data.interview_uuid', $interview->uuid)
            ->assertJsonMissingPath('data.sender.email')->assertJsonMissingPath('data.private_notes');
        $messageId = $response->json('data.id');
        $this->actingAs($applicant, 'applicant')->postJson($this->url($interview, 'applicant'), [
            'body' => "Applicant reply\nsecond line", 'client_message_id' => $clientId,
        ])->assertCreated()->assertJsonPath('data.sender.key', 'applicant:'.$applicant->id);
        $this->getJson($this->url($interview, 'applicant'))->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $messageId)->assertJsonPath('data.0.body', $body)
            ->assertJsonPath('meta.can_send', true)->assertJsonPath('meta.has_more', false)
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->assertDatabaseHas('recruitment_interview_messages', ['id' => $messageId, 'sender_kind' => 'user', 'sender_id' => $staff->id, 'body' => $body]);
        $this->assertDatabaseCount('recruitment_interview_messages', 2);
        $this->assertNull($interview->refresh()->started_at);
        Event::assertDispatchedTimes(InterviewMessageSent::class, 2);
        Event::assertDispatched(InterviewMessageSent::class, function (InterviewMessageSent $event) use ($interview, $messageId): bool {
            if ($event->messageId !== $messageId) {
                return false;
            }
            $this->assertSame('interview.message', $event->broadcastAs());
            $this->assertSame('private-Recruitment.Interview.'.$interview->uuid, (string) $event->broadcastOn()[0]);
            $this->assertSame(['interview_uuid' => $interview->uuid, 'message_id' => $messageId], $event->broadcastWith());

            return true;
        });
    }

    public function test_retries_use_the_same_actor_uuid_once_and_cannot_replace_a_saved_message(): void
    {
        [$interview, $staff] = $this->room();
        Event::fake([InterviewMessageSent::class]);
        $input = ['body' => 'Saved once', 'client_message_id' => strtoupper((string) Str::uuid())];
        $id = $this->actingAs($staff, 'web')->postJson($this->url($interview), $input)
            ->assertCreated()->json('data.id');
        $input['client_message_id'] = strtolower($input['client_message_id']);
        $this->postJson($this->url($interview), $input)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson($this->url($interview), array_replace($input, ['body' => 'Changed']))
            ->assertUnprocessable()->assertJsonValidationErrors('client_message_id');
        $interview->update(['status' => 'completed']);
        $this->postJson($this->url($interview), $input)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson($this->url($interview), ['body' => 'New after ending', 'client_message_id' => (string) Str::uuid()])->assertConflict();
        $this->getJson($this->url($interview))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.can_send', false);
        $this->assertDatabaseCount('recruitment_interview_messages', 1);
        Event::assertDispatchedTimes(InterviewMessageSent::class, 3);
    }

    public function test_foreign_applicants_unassigned_staff_and_the_wrong_guard_cannot_access_transcripts(): void
    {
        [$interview, $staff, $applicant] = $this->room();
        $other = Applicant::factory()->create();
        $this->actingAs($staff, 'web')->getJson($this->url($interview, 'applicant'))->assertUnauthorized();
        Auth::guard('web')->logout();
        $this->actingAs($applicant, 'applicant')->getJson($this->url($interview))->assertUnauthorized();
        $this->actingAs($other, 'applicant')->getJson($this->url($interview, 'applicant'))->assertNotFound();
        $this->postJson($this->url($interview, 'applicant'), ['body' => 'No', 'client_message_id' => (string) Str::uuid()])->assertNotFound();
        $this->actingAs($this->user('recruitment.view', 'recruitment.manage'), 'web')->getJson($this->url($interview))->assertForbidden();
        $this->postJson($this->url($interview), ['body' => 'No', 'client_message_id' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('recruitment_interview_messages', 0);
    }

    public function test_message_policy_requires_a_current_active_assigned_participant(): void
    {
        [$interview, $staff, $applicant] = $this->room();
        $unassigned = $this->user('recruitment.view', 'recruitment.manage');
        $reader = $this->user('recruitment.view');
        $interview->interviewers()->attach($reader);
        foreach (['readMessages', 'sendMessage'] as $ability) {
            $this->assertTrue(Gate::forUser($staff)->allows($ability, $interview));
            $this->assertTrue(Gate::forUser($applicant)->allows($ability, $interview));
            $this->assertFalse(Gate::forUser($unassigned)->allows($ability, $interview));
            $this->assertFalse(Gate::forUser($reader)->allows($ability, $interview));
            $this->assertSame(404, Gate::forUser(Applicant::factory()->create())->inspect($ability, $interview)->status());
        }
        $applicant->update(['is_active' => false]);
        $staff->update(['is_active' => false]);
        $this->assertFalse(Gate::forUser($applicant)->allows('readMessages', $interview));
        $this->assertFalse(Gate::forUser($staff)->allows('sendMessage', $interview));
    }

    public function test_removed_or_disabled_participants_cannot_read_again_or_replay_a_saved_message(): void
    {
        [$interview, $staff, $applicant] = $this->room();
        Event::fake([InterviewMessageSent::class]);
        $input = ['body' => 'Before removal', 'client_message_id' => (string) Str::uuid()];
        $this->actingAs($staff, 'web')->postJson($this->url($interview), $input)->assertCreated();
        $interview->interviewers()->detach($staff);
        $this->getJson($this->url($interview))->assertForbidden();
        $this->postJson($this->url($interview), $input)->assertForbidden();
        $applicant->update(['is_active' => false]);
        $this->actingAs($applicant, 'applicant')->getJson($this->url($interview, 'applicant'))->assertForbidden();
        $this->assertDatabaseCount('recruitment_interview_messages', 1);
        Event::assertDispatchedTimes(InterviewMessageSent::class, 1);
    }

    public function test_service_reloads_a_stale_roster_and_permission_before_reading_or_writing(): void
    {
        [$interview, $staff] = $this->room();
        $interview->load('interviewers');
        $interview->interviewers()->detach($staff);
        foreach (['read', 'send'] as $operation) {
            try {
                $service = app(RecruitmentInterviewMessageService::class);
                if ($operation === 'read') {
                    $service->history($interview, $staff);
                } else {
                    $service->send($interview, $staff, ['body' => 'Stale actor', 'client_message_id' => (string) Str::uuid()]);
                }
                $this->fail('A removed participant must not access messages through a stale model.');
            } catch (AuthorizationException $exception) {
                $this->assertSame('This action is unauthorized.', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('recruitment_interview_messages', 0);
    }

    public function test_latest_older_and_after_cursors_are_bounded_chronological_and_room_scoped(): void
    {
        [$interview, $staff] = $this->room();
        $rows = RecruitmentInterviewMessage::factory()->count(105)->for($interview, 'interview')->create();
        $foreign = RecruitmentInterviewMessage::factory()->create(['body' => 'Other room private text']);
        $this->actingAs($staff, 'web');
        $response = $this->getJson($this->url($interview))->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('meta.has_more', true);
        $this->assertSame($rows->slice(55)->values()->modelKeys(), array_column($response->json('data'), 'id'));
        $response = $this->getJson($this->url($interview).'?before_id='.$rows[55]->id)->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('meta.has_more', true);
        $this->assertSame($rows->slice(5, 50)->values()->modelKeys(), array_column($response->json('data'), 'id'));
        $this->getJson($this->url($interview).'?before_id='.$rows[5]->id)->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.has_more', false);
        $response = $this->getJson($this->url($interview).'?after_id=0')->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('meta.has_more', true);
        $this->assertSame($rows->take(50)->modelKeys(), array_column($response->json('data'), 'id'));
        $this->getJson($this->url($interview).'?after_id='.$rows[99]->id)->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.has_more', false);
        foreach (['before_id', 'after_id'] as $field) {
            $this->getJson($this->url($interview).'?'.$field.'='.$foreign->id)->assertUnprocessable()->assertJsonValidationErrors($field)->assertJsonMissing(['body' => $foreign->body]);
        }
    }

    #[DataProvider('invalidCursors')]
    public function test_invalid_cursor_shapes_are_rejected(string $query, string $field): void
    {
        [$interview, $staff] = $this->room();
        $this->actingAs($staff, 'web')->getJson($this->url($interview).'?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    #[DataProvider('invalidMessages')]
    public function test_invalid_message_content_and_identifiers_never_persist(mixed $body, mixed $uuid, string $field): void
    {
        [$interview, $staff] = $this->room();
        Event::fake([InterviewMessageSent::class]);
        $this->actingAs($staff, 'web')->postJson($this->url($interview), ['body' => $body, 'client_message_id' => $uuid])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('recruitment_interview_messages', 0);
        Event::assertNotDispatched(InterviewMessageSent::class);
    }

    public function test_service_validates_input_even_without_a_form_request(): void
    {
        [$interview, $staff] = $this->room();
        foreach ([['body' => str_repeat('é', 1001), 'client_message_id' => (string) Str::uuid()], ['body' => 'Hello', 'client_message_id' => 'invalid']] as $input) {
            try {
                app(RecruitmentInterviewMessageService::class)->send($interview, $staff, $input);
                $this->fail('Invalid message data must be rejected by the service.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertDatabaseCount('recruitment_interview_messages', 0);
    }

    public function test_future_cancelled_and_nonapproved_rooms_have_read_only_transcripts(): void
    {
        [$interview, $staff] = $this->room();
        RecruitmentInterviewMessage::factory()->for($interview, 'interview')->create();
        $this->actingAs($staff, 'web');
        foreach (['future', 'cancelled', 'application_closed'] as $state) {
            $interview->update(['scheduled_at' => $state === 'future' ? now()->addDay() : now(), 'status' => $state === 'cancelled' ? 'cancelled' : 'scheduled']);
            $interview->application->update(['status' => $state === 'application_closed' ? 'hired' : 'approved']);
            $this->getJson($this->url($interview))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.can_send', false);
            $this->postJson($this->url($interview), ['body' => 'Closed', 'client_message_id' => (string) Str::uuid()])->assertConflict();
        }
        $this->assertDatabaseCount('recruitment_interview_messages', 1);
    }

    public function test_broadcast_is_queued_with_only_an_invalidation_and_rolled_back_messages_never_publish(): void
    {
        [$interview, $staff] = $this->room();
        Queue::fake([BroadcastEvent::class]);
        $message = app(RecruitmentInterviewMessageService::class)->send($interview, $staff, ['body' => 'Not in websocket payload', 'client_message_id' => (string) Str::uuid()]);
        Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job): bool => $job->event instanceof InterviewMessageSent
            && $job->event->broadcastWith() === ['interview_uuid' => $interview->uuid, 'message_id' => $message->id]);
        Event::fake([InterviewMessageSent::class]);
        try {
            DB::transaction(function () use ($interview, $staff): void {
                app(RecruitmentInterviewMessageService::class)->send($interview, $staff, ['body' => 'Rolled back', 'client_message_id' => (string) Str::uuid()]);
                throw new RuntimeException('Fixture rollback.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Fixture rollback.', $exception->getMessage());
        }
        Event::assertNotDispatched(InterviewMessageSent::class);
        $this->assertDatabaseCount('recruitment_interview_messages', 1);
    }

    public function test_unique_actor_retry_key_and_interview_deletion_preserve_schema_invariants(): void
    {
        [$interview] = $this->room();
        $message = RecruitmentInterviewMessage::factory()->for($interview, 'interview')->create();
        try {
            DB::transaction(fn () => RecruitmentInterviewMessage::query()->create($message->only(['recruitment_interview_id', 'sender_kind', 'sender_id', 'sender_name', 'client_message_id', 'body'])));
            $this->fail('A duplicated actor retry key must be refused by the database.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->errorInfo);
        }
        $this->assertDatabaseCount('recruitment_interview_messages', 1);
        $interview->delete();
        $this->assertDatabaseCount('recruitment_interview_messages', 0);
    }

    public function test_send_rate_limit_is_separate_for_staff_and_applicant_with_the_same_numeric_id(): void
    {
        $this->freezeTime();
        [$interview, $staff, $applicant] = $this->room();
        Event::fake([InterviewMessageSent::class]);
        $this->assertSame($staff->id, $applicant->id);
        $input = ['body' => 'Retry', 'client_message_id' => (string) Str::uuid()];
        $this->actingAs($staff, 'web');
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $response = $this->postJson($this->url($interview), $input);
            $attempt === 0 ? $response->assertCreated() : $response->assertOk();
        }
        $this->postJson($this->url($interview), $input)->assertTooManyRequests();
        $this->actingAs($applicant, 'applicant')->postJson($this->url($interview, 'applicant'), $input)->assertCreated();
        $this->assertDatabaseCount('recruitment_interview_messages', 2);
    }

    /** @return array{RecruitmentInterview, User, Applicant} */
    private function room(): array
    {
        $staff = $this->user('recruitment.view', 'recruitment.manage');
        $interview = RecruitmentInterview::factory()->hasAttached($staff, [], 'interviewers')->create(['scheduled_at' => now()]);

        return [$interview, $staff, $interview->application->applicant];
    }

    private function url(RecruitmentInterview $interview, string $scope = 'recruitment'): string
    {
        return '/api/v1/'.$scope.'/interviews/'.$interview->id.'/messages';
    }

    public static function protectedEndpoints(): array
    {
        return [['GET', 'recruitment'], ['POST', 'recruitment'], ['GET', 'applicant'], ['POST', 'applicant']];
    }

    public static function invalidCursors(): array
    {
        return [
            ['after_id=-1', 'after_id'], ['before_id=0', 'before_id'], ['after_id[]=1', 'after_id'],
            ['after_id=text', 'after_id'], ['after_id=0&before_id=1', 'after_id'],
            ['after_id=99999999999999999999999999999', 'after_id'],
        ];
    }

    public static function invalidMessages(): array
    {
        $uuid = '5d823e1e-659f-4c78-bb62-7334fd2a40cf';

        return [
            ['', $uuid, 'body'], [null, $uuid, 'body'], [['text'], $uuid, 'body'],
            [str_repeat('a', 2001), $uuid, 'body'], [str_repeat('é', 1001), $uuid, 'body'],
            ["\u{00A0}\u{2003}", $uuid, 'body'], ["a\0b", $uuid, 'body'],
            ['Hello', null, 'client_message_id'], ['Hello', 'bad-uuid', 'client_message_id'],
        ];
    }
}
