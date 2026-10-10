<?php

namespace Tests\Feature;

use App\Http\Requests\AuthorizeInterviewBroadcastRequest;
use App\Http\Requests\CancelRecruitmentInterviewRequest;
use App\Http\Requests\CompleteRecruitmentInterviewRequest;
use App\Http\Requests\SignalRecruitmentInterviewRequest;
use App\Http\Requests\StoreRecruitmentInterviewRequest;
use App\Models\JobApplication;
use App\Models\RecruitmentInterview;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class RecruitmentInterviewFormRequestTest extends FormRequestTestCase
{
    public function test_schedule_validates_only_owned_fields_and_accepts_a_four_member_panel(): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $staff = $this->user('recruitment.manage');
        $panel = [$staff, $this->user('recruitment.manage'), $this->user('recruitment.manage'), $this->user('recruitment.manage')];
        $application = JobApplication::factory()->approved()->create();
        $input = ['round' => 'initial', 'scheduled_at' => '2026-10-08T09:00:00+08:00', 'duration_minutes' => 120, 'interviewer_ids' => array_map(fn ($user): int => $user->id, $panel), 'private_notes' => 'Private deliberation.'];

        $validated = $this->validateRequest(StoreRecruitmentInterviewRequest::class, $input + ['status' => 'completed', 'uuid' => 'spoofed', 'scheduled_by' => 999], $staff, ['jobApplication' => $application]);

        ksort($input);
        ksort($validated);
        $this->assertSame($input, $validated);
    }

    #[DataProvider('invalidSchedules')]
    public function test_schedule_rejects_invalid_input_with_a_user_visible_field_error(array $changes, string $field): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $staff = $this->user('recruitment.manage');
        $application = JobApplication::factory()->approved()->create();
        $input = array_replace(['round' => 'initial', 'scheduled_at' => '2026-10-08T09:00:00+08:00', 'duration_minutes' => 30, 'interviewer_ids' => [$staff->id]], $changes);

        try {
            $this->validateRequest(StoreRecruitmentInterviewRequest::class, $input, $staff, ['jobApplication' => $application]);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
            $this->assertNotEmpty($exception->errors()[$field][0]);

            return;
        }
        $this->fail('Expected the invalid interview scheduling field to be rejected.');
    }

    public function test_schedule_requires_distinct_active_staff_with_recruitment_management_permission(): void
    {
        $this->travelTo('2026-10-07 09:00:00');
        $staff = $this->user('recruitment.manage');
        $inactive = $this->user('recruitment.manage');
        $inactive->update(['is_active' => false]);
        $unprivileged = $this->user();
        $application = JobApplication::factory()->approved()->create();
        $input = ['round' => 'initial', 'scheduled_at' => '2026-10-08T09:00:00+08:00', 'duration_minutes' => 30];

        $this->assertInvalidRequest(StoreRecruitmentInterviewRequest::class, $input + ['interviewer_ids' => [$staff->id, $staff->id]], $staff, ['jobApplication' => $application], ['interviewer_ids.0']);
        $this->assertInvalidRequest(StoreRecruitmentInterviewRequest::class, $input + ['interviewer_ids' => [$inactive->id]], $staff, ['jobApplication' => $application], ['interviewer_ids']);
        $this->assertInvalidRequest(StoreRecruitmentInterviewRequest::class, $input + ['interviewer_ids' => [$unprivileged->id]], $staff, ['jobApplication' => $application], ['interviewer_ids']);
    }

    #[DataProvider('invalidSignals')]
    public function test_signal_validation_rejects_spoofed_nested_fields_and_oversized_or_malformed_payloads(array $input, string $field): void
    {
        $staff = $this->user('recruitment.manage');
        $interview = RecruitmentInterview::factory()->hasAttached($staff, [], 'interviewers')->create();

        $this->assertInvalidRequest(SignalRecruitmentInterviewRequest::class, $input, $staff, ['recruitmentInterview' => $interview], [$field]);
    }

    public function test_ready_and_leave_allow_empty_payloads_with_optional_canonical_recipient(): void
    {
        $staff = $this->user('recruitment.manage');
        $interview = RecruitmentInterview::factory()->hasAttached($staff, [], 'interviewers')->create();

        $this->assertSame(['type' => 'ready', 'payload' => []], $this->validateRequest(SignalRecruitmentInterviewRequest::class, ['type' => 'ready', 'payload' => [], 'sender' => 'spoofed'], $staff, ['recruitmentInterview' => $interview]));
        $this->assertSame(['type' => 'leave', 'target_key' => 'applicant:1', 'payload' => []], $this->validateRequest(SignalRecruitmentInterviewRequest::class, ['type' => 'leave', 'target_key' => 'applicant:1', 'payload' => []], $staff, ['recruitmentInterview' => $interview]));
    }

    public function test_lifecycle_notes_are_private_bounded_and_require_manager_authorization(): void
    {
        $staff = $this->user('recruitment.manage');
        $viewer = $this->user('recruitment.view');
        $interview = RecruitmentInterview::factory()->create();

        $this->assertSame(['private_notes' => 'Reviewed.'], $this->validateRequest(CompleteRecruitmentInterviewRequest::class, ['private_notes' => 'Reviewed.', 'status' => 'hired'], $staff, ['recruitmentInterview' => $interview]));
        $this->assertInvalidRequest(CancelRecruitmentInterviewRequest::class, ['private_notes' => str_repeat('N', 5001)], $staff, ['recruitmentInterview' => $interview], ['private_notes']);
        $this->assertUnauthorizedRequest(CompleteRecruitmentInterviewRequest::class, [], $viewer, ['recruitmentInterview' => $interview]);
    }

    public function test_private_channel_request_accepts_only_canonical_interview_rooms_and_socket_ids(): void
    {
        $staff = $this->user();
        $channel = 'private-Recruitment.Interview.01234567-89ab-4cde-8fab-0123456789ab';
        $this->assertSame(['socket_id' => '123.456', 'channel_name' => $channel], $this->validateRequest(AuthorizeInterviewBroadcastRequest::class, ['socket_id' => '123.456', 'channel_name' => $channel], $staff));
        $this->assertInvalidRequest(AuthorizeInterviewBroadcastRequest::class, ['socket_id' => 'invalid', 'channel_name' => $channel], $staff, fields: ['socket_id']);
        $this->assertInvalidRequest(AuthorizeInterviewBroadcastRequest::class, ['socket_id' => '123.456', 'channel_name' => 'private-App.Models.User.1'], $staff, fields: ['channel_name']);
    }

    public static function invalidSchedules(): array
    {
        return [
            'missing round' => [['round' => null], 'round'],
            'unsupported round' => [['round' => 'third'], 'round'],
            'missing time' => [['scheduled_at' => null], 'scheduled_at'],
            'array time' => [['scheduled_at' => []], 'scheduled_at'],
            'offset missing' => [['scheduled_at' => '2026-10-08T09:00:00'], 'scheduled_at'],
            'invalid date' => [['scheduled_at' => '2026-02-30T09:00:00+08:00'], 'scheduled_at'],
            'past' => [['scheduled_at' => '2026-10-07T08:00:00+08:00'], 'scheduled_at'],
            'too short' => [['duration_minutes' => 14], 'duration_minutes'],
            'too long' => [['duration_minutes' => 121], 'duration_minutes'],
            'fractional duration' => [['duration_minutes' => 30.5], 'duration_minutes'],
            'empty panel' => [['interviewer_ids' => []], 'interviewer_ids'],
            'too many panelists' => [['interviewer_ids' => [1, 2, 3, 4, 5]], 'interviewer_ids'],
            'nonexistent panelist' => [['interviewer_ids' => [999999]], 'interviewer_ids.0'],
            'oversized notes' => [['private_notes' => str_repeat('N', 5001)], 'private_notes'],
        ];
    }

    public static function invalidSignals(): array
    {
        return [
            'missing type' => [[], 'type'],
            'unsupported type' => [['type' => 'record'], 'type'],
            'offer missing recipient' => [['type' => 'offer', 'payload' => ['sdp' => 'v=0']], 'target_key'],
            'noncanonical recipient' => [['type' => 'ready', 'target_key' => 'user:01'], 'target_key'],
            'offer missing SDP' => [['type' => 'offer', 'target_key' => 'applicant:1', 'payload' => []], 'payload.sdp'],
            'oversized SDP' => [['type' => 'offer', 'target_key' => 'applicant:1', 'payload' => ['sdp' => str_repeat('S', 8193)]], 'payload.sdp'],
            'spoofed payload' => [['type' => 'answer', 'target_key' => 'applicant:1', 'payload' => ['sdp' => 'v=0', 'sender' => 'spoof']], 'payload'],
            'ready with data' => [['type' => 'ready', 'payload' => ['recording' => true]], 'payload'],
            'oversized candidate' => [['type' => 'ice', 'target_key' => 'applicant:1', 'payload' => ['candidate' => str_repeat('C', 4097)]], 'payload.candidate'],
            'oversized mid' => [['type' => 'ice', 'target_key' => 'applicant:1', 'payload' => ['candidate' => 'candidate:fixture', 'sdpMid' => str_repeat('M', 101)]], 'payload.sdpMid'],
            'invalid line index' => [['type' => 'ice', 'target_key' => 'applicant:1', 'payload' => ['candidate' => 'candidate:fixture', 'sdpMLineIndex' => 101]], 'payload.sdpMLineIndex'],
        ];
    }
}
