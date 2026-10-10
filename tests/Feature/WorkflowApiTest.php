<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\CtoBalance;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\EmployeeProfileChangeRequest;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class WorkflowApiTest extends FormRequestTestCase
{
    #[DataProvider('endpoints')]
    public function test_workflow_endpoints_require_authentication(string $path): void
    {
        $this->getJson('/api/v1/'.$path)->assertUnauthorized();
        $this->postJson('/api/v1/'.$path, [])->assertUnauthorized();
    }

    #[DataProvider('endpoints')]
    public function test_workflow_endpoints_reject_insufficient_permissions(string $path): void
    {
        $this->actingAs($this->user());

        $this->getJson('/api/v1/'.$path)->assertForbidden();
        $this->postJson('/api/v1/'.$path, [])->assertForbidden();
    }

    #[DataProvider('workflows')]
    public function test_workflow_index_and_show_protect_other_employees(string $module, string $path, string $prefix): void
    {
        $owner = $this->user($prefix.'.view');
        $own = $this->record($module, $this->employee($owner), $owner);
        $other = $this->record($module, $this->employee(), $owner);

        $this->actingAs($owner)->getJson('/api/v1/'.$path)
            ->assertOk()->assertJsonPath('data.0.id', $own->id)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/'.$path.'/'.$own->id)->assertOk()->assertJsonPath('data.id', $own->id);
        $this->getJson('/api/v1/'.$path.'/'.$other->id)->assertForbidden();
    }

    #[DataProvider('workflows')]
    public function test_workflow_team_scope_matches_individual_view_policy(string $module, string $path, string $prefix): void
    {
        $viewer = $this->user($prefix.'.view', 'employees.view-team');
        $supervisor = $this->employee($viewer);
        $own = $this->record($module, $supervisor, $viewer);
        $report = $this->record($module, $this->employee(null, ['supervisor_employee_id' => $supervisor->id]), $viewer);
        $unrelated = $this->record($module, $this->employee(), $viewer);
        $expected = $module === 'EmployeeProfileChange' ? [$own->id] : [$report->id, $own->id];

        $response = $this->actingAs($viewer)->getJson('/api/v1/'.$path);

        $response->assertOk();
        $this->assertSame($expected, array_column($response->json('data'), 'id'));
        $this->getJson('/api/v1/'.$path.'/'.$unrelated->id)->assertForbidden();
    }

    #[DataProvider('workflows')]
    public function test_workflow_privileged_viewer_can_see_all_employees(string $module, string $path, string $prefix): void
    {
        $viewer = $this->user($prefix.'.view', 'employees.view');
        $record = $this->record($module, $this->employee(), $viewer);

        $this->actingAs($viewer)->getJson('/api/v1/'.$path)->assertOk()->assertJsonPath('data.0.id', $record->id);
        $this->getJson('/api/v1/'.$path.'/'.$record->id)->assertOk()->assertJsonPath('data.id', $record->id);
    }

    #[DataProvider('workflows')]
    public function test_workflow_viewer_without_employee_gets_an_empty_list(string $module, string $path, string $prefix): void
    {
        $viewer = $this->user($prefix.'.view');
        $this->record($module, $this->employee(), $viewer);

        $this->actingAs($viewer)->getJson('/api/v1/'.$path)->assertOk()->assertJsonCount(0, 'data');
    }

    #[DataProvider('workflows')]
    public function test_workflow_submission_uses_authenticated_employee_and_server_fields(string $module, string $path, string $prefix): void
    {
        $owner = $this->user($prefix.'.create');
        $employee = $this->employee($owner);
        $payload = $this->submission($module, $employee) + [
            'employee_id' => $this->employee()->id,
            'created_by_user_id' => $this->user()->id,
            'status' => 'approved',
            'requested_minutes' => 9999,
            'approved_minutes' => 9999,
            'approved_units' => '9999',
            'reviewed_by_user_id' => $owner->id,
        ];

        $response = $this->actingAs($owner)->postJson('/api/v1/'.$path, $payload);

        $response->assertCreated()->assertJsonPath('data.employee_id', $employee->id)
            ->assertJsonPath('data.created_by_user_id', $owner->id)->assertJsonPath('data.status', 'pending');
        $record = self::model($module)::query()->findOrFail($response->json('data.id'));
        $this->assertSame($employee->id, $record->employee_id);
        $this->assertSame($owner->id, $record->created_by_user_id);
        $this->assertSame('pending', $record->status);
        $this->assertNull($record->reviewed_by_user_id);

        if (in_array($module, ['Cto', 'Overtime'], true)) {
            $response->assertJsonPath('data.requested_minutes', 60)->assertJsonPath('data.approved_minutes', null);
            $this->assertSame(60, $record->requested_minutes);
        }

        if ($module === 'EmployeeProfileChange') {
            $response->assertJsonPath('data.items.0.field_name', 'first_name')
                ->assertJsonPath('data.items.0.old_value', 'Request')
                ->assertJsonPath('data.items.0.new_value', 'Updated');
            $this->assertDatabaseHas('employee_profile_change_items', [
                'employee_profile_change_request_id' => $record->id,
                'field_name' => 'first_name', 'old_value' => 'Request', 'new_value' => 'Updated',
            ]);
        }

        $this->assertSafeResource($response->json('data'));
    }

    #[DataProvider('workflows')]
    public function test_workflow_submission_exercises_validation_without_persisting(string $module, string $path, string $prefix): void
    {
        $owner = $this->user($prefix.'.create');
        $this->employee($owner);
        $before = self::model($module)::query()->count();
        $field = match ($module) {
            'EmployeeProfileChange' => 'changes',
            'AttendanceCorrection' => 'attendance_date',
            'Leave' => 'leave_type_id',
            default => 'start_at',
        };

        $this->actingAs($owner)->postJson('/api/v1/'.$path, [])
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertSame($before, self::model($module)::query()->count());
    }

    #[DataProvider('supervisedWorkflows')]
    public function test_workflow_owner_cannot_approve_their_request(string $module, string $path, string $prefix): void
    {
        $owner = $this->user($prefix.'.create', $prefix.'.approve');
        $record = $this->record($module, $this->employee($owner), $owner);

        $this->actingAs($owner)->postJson('/api/v1/'.$path.'/'.$record->id.'/approve', self::approval($module))->assertForbidden();

        $this->assertSame('pending', $record->fresh()->status);
        $this->assertDatabaseCount('approval_actions', 0);
    }

    #[DataProvider('workflows')]
    public function test_workflow_approval_calls_service_and_persists_server_reviewer(string $module, string $path, string $prefix): void
    {
        [$reviewer, $owner, $employee] = $this->reviewFixture($prefix);
        $record = $this->record($module, $employee, $owner);

        if ($module === 'Cto') {
            CtoBalance::query()->create(['employee_id' => $employee->id, 'balance_minutes' => 120]);
        }

        $response = $this->actingAs($reviewer)->postJson('/api/v1/'.$path.'/'.$record->id.'/approve', self::approval($module) + [
            'reviewed_by_user_id' => $owner->id, 'status' => 'cancelled',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.reviewed_by_user_id', $reviewer->id);
        $this->assertSame('approved', $record->fresh()->status);
        $this->assertSame($reviewer->id, $record->fresh()->reviewed_by_user_id);

        if ($module === 'EmployeeProfileChange') {
            $response->assertJsonPath('data.items.0.field_name', 'first_name')
                ->assertJsonPath('data.items.0.new_value', 'Updated');
            $this->assertSame('Request', $employee->fresh()->first_name);
        } else {
            $this->assertDatabaseHas('approval_actions', [
                'approvable_type' => $record->getMorphClass(), 'approvable_id' => $record->id,
                'acted_by_user_id' => $reviewer->id, 'action' => 'approved',
            ]);
        }

        if ($module === 'Cto') {
            $this->assertSame(90, CtoBalance::query()->where('employee_id', $employee->id)->value('balance_minutes'));
            $this->assertDatabaseHas('cto_credit_transactions', ['cto_request_id' => $record->id, 'minutes' => -30, 'transaction_type' => 'cto_usage']);
        }

        if ($module === 'AttendanceCorrection') {
            $attendance = $record->fresh()->dailyAttendance;
            $this->assertSame('2026-10-03 09:00:00', $attendance->actual_in->format('Y-m-d H:i:s'));
            $this->assertSame(480, $attendance->worked_minutes);
        }

        $this->assertSafeResource($response->json('data'));
    }

    #[DataProvider('workflows')]
    public function test_workflow_rejection_calls_service_with_optional_remarks(string $module, string $path, string $prefix): void
    {
        [$reviewer, $owner, $employee] = $this->reviewFixture($prefix);
        $record = $this->record($module, $employee, $owner);

        $this->actingAs($reviewer)->postJson('/api/v1/'.$path.'/'.$record->id.'/reject', ['remarks' => 'Revise request.'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.review_remarks', 'Revise request.');

        $this->assertSame('rejected', $record->fresh()->status);
        $this->assertSame($reviewer->id, $record->fresh()->reviewed_by_user_id);
    }

    #[DataProvider('workflows')]
    public function test_workflow_cancellation_uses_owner_and_optional_reason(string $module, string $path, string $prefix): void
    {
        $owner = $this->user($prefix.'.create');
        $record = $this->record($module, $this->employee($owner), $owner);

        $this->actingAs($owner)->postJson('/api/v1/'.$path.'/'.$record->id.'/cancel', ['reason' => 'Plans changed.'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancellation_reason', 'Plans changed.');

        $this->assertSame('cancelled', $record->fresh()->status);
        $this->assertNotNull($record->fresh()->cancelled_at);

        if ($module !== 'EmployeeProfileChange') {
            $this->assertDatabaseHas('approval_actions', [
                'approvable_type' => $record->getMorphClass(), 'approvable_id' => $record->id,
                'acted_by_user_id' => $owner->id, 'action' => 'cancelled',
            ]);
        }
    }

    #[DataProvider('workflows')]
    public function test_workflow_wrong_state_returns_conflict_without_rewriting_review(string $module, string $path, string $prefix): void
    {
        [$reviewer, $owner, $employee] = $this->reviewFixture($prefix);
        $record = $this->record($module, $employee, $owner);
        $record->update(['status' => 'rejected', 'reviewed_by_user_id' => $reviewer->id, 'review_remarks' => 'Original rejection.']);

        $this->actingAs($reviewer)->postJson('/api/v1/'.$path.'/'.$record->id.'/reject', ['remarks' => 'Overwrite.'])->assertConflict();

        $this->assertSame('Original rejection.', $record->fresh()->review_remarks);
        $this->assertDatabaseCount('approval_actions', 0);
    }

    public function test_employee_profile_change_apply_requires_approval_and_updates_employee(): void
    {
        $owner = $this->user('profile-changes.create');
        $reviewer = $this->user('profile-changes.approve');
        $employee = $this->employee($owner);
        $record = $this->record('EmployeeProfileChange', $employee, $owner);
        $record->update(['status' => 'approved', 'reviewed_by_user_id' => $reviewer->id]);

        $this->actingAs($reviewer)->postJson('/api/v1/employee-profile-change-requests/'.$record->id.'/apply')
            ->assertOk()->assertJsonPath('data.status', 'applied')->assertJsonPath('data.applied_by_user_id', $reviewer->id);

        $this->assertSame('Updated', $employee->fresh()->first_name);
        $this->assertSame('applied', $record->fresh()->status);
    }

    public function test_attendance_correction_foreign_attendance_cannot_be_submitted(): void
    {
        $owner = $this->user('attendance-corrections.create');
        $employee = $this->employee($owner);
        $payload = $this->submission('AttendanceCorrection', $employee);
        $foreign = DailyAttendance::query()->create([
            'employee_id' => $this->employee()->id, 'attendance_date' => '2026-10-03', 'status' => 'absent',
        ]);
        $payload['daily_attendance_id'] = $foreign->id;

        $this->actingAs($owner)->postJson('/api/v1/attendance-correction-requests', $payload)->assertConflict();

        $this->assertDatabaseCount('attendance_correction_requests', 0);
    }

    public function test_cto_insufficient_balance_rolls_back_approval_and_ledger(): void
    {
        [$reviewer, $owner, $employee] = $this->reviewFixture('cto');
        $record = $this->record('Cto', $employee, $owner);
        CtoBalance::query()->create(['employee_id' => $employee->id, 'balance_minutes' => 10]);

        $this->actingAs($reviewer)->postJson('/api/v1/cto-requests/'.$record->id.'/approve', ['approved_minutes' => 30])->assertConflict();

        $this->assertSame('pending', $record->fresh()->status);
        $this->assertSame(10, CtoBalance::query()->where('employee_id', $employee->id)->value('balance_minutes'));
        $this->assertDatabaseCount('cto_credit_transactions', 0);
        $this->assertDatabaseCount('approval_actions', 0);
    }

    public function test_leave_approval_debits_balance_and_records_ledger(): void
    {
        [$reviewer, $owner, $employee] = $this->reviewFixture('leave');
        $record = $this->record('Leave', $employee, $owner);
        $record->leaveType->update(['requires_balance' => true]);
        LeaveBalance::query()->create([
            'employee_id' => $employee->id, 'leave_type_id' => $record->leave_type_id,
            'year' => 2026, 'balance' => '2.00',
        ]);

        $this->actingAs($reviewer)->postJson('/api/v1/leave-requests/'.$record->id.'/approve', ['approved_units' => '0.50'])
            ->assertOk()->assertJsonPath('data.approved_units', '0.50');

        $this->assertSame('1.50', LeaveBalance::query()->where('employee_id', $employee->id)->value('balance'));
        $this->assertDatabaseHas('leave_credit_transactions', ['leave_request_id' => $record->id, 'units' => '-0.50', 'transaction_type' => 'leave_approval']);
    }

    public function test_system_settings_create_and_update_use_service_encoding_and_trusted_actor(): void
    {
        $manager = $this->user('settings.manage', 'settings.view');
        $response = $this->actingAs($manager)->postJson('/api/v1/system-settings', [
            'group' => 'attendance', 'key' => 'attendance.enabled', 'type' => 'boolean', 'value' => true,
            'updated_by_user_id' => $this->user()->id,
        ]);

        $response->assertCreated()->assertJsonPath('data.value', true)->assertJsonPath('data.updated_by_user_id', $manager->id);
        $id = $response->json('data.id');
        $this->assertDatabaseHas('system_settings', ['id' => $id, 'value' => '1', 'type' => 'boolean', 'updated_by_user_id' => $manager->id]);

        $this->putJson('/api/v1/system-settings/'.$id, ['value' => false, 'type' => 'integer', 'key' => 'spoofed.key'])
            ->assertOk()->assertJsonPath('data.value', false)->assertJsonPath('data.type', 'boolean')->assertJsonPath('data.key', 'attendance.enabled');

        $this->assertDatabaseHas('system_settings', ['id' => $id, 'value' => '0', 'type' => 'boolean', 'key' => 'attendance.enabled']);
    }

    public function test_system_settings_noneditable_value_returns_conflict_and_keeps_original(): void
    {
        $manager = $this->user('settings.manage');
        $setting = SystemSetting::query()->create([
            'group' => 'system', 'key' => 'locked.key', 'type' => 'string', 'value' => 'original', 'is_editable' => false,
        ]);

        $this->actingAs($manager)->putJson('/api/v1/system-settings/'.$setting->id, ['value' => 'replacement'])->assertConflict();

        $this->assertSame('original', $setting->fresh()->value);
    }

    public function test_system_settings_invalid_value_returns_field_validation_error(): void
    {
        $manager = $this->user('settings.manage');

        $this->actingAs($manager)->postJson('/api/v1/system-settings', [
            'group' => 'system', 'key' => 'integer.key', 'type' => 'integer', 'value' => 'not-integer',
        ])->assertUnprocessable()->assertJsonValidationErrors('value');

        $this->assertDatabaseCount('system_settings', 0);
    }

    public function test_announcements_viewers_see_only_current_publications(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(12, 0));
        $viewer = $this->user('announcements.view');
        $visible = Announcement::query()->create(['title' => 'Current', 'content' => 'Current announcement.', 'published_at' => now()->subDay(), 'is_active' => true]);
        $draft = Announcement::query()->create(['title' => 'Draft', 'content' => 'Draft announcement.']);
        Announcement::query()->create(['title' => 'Future', 'content' => 'Scheduled.', 'published_at' => now()->addDay()]);
        Announcement::query()->create(['title' => 'Expired', 'content' => 'Old.', 'published_at' => now()->subDays(2), 'expires_at' => now()->subDay()]);
        Announcement::query()->create(['title' => 'Inactive', 'content' => 'Disabled.', 'published_at' => now()->subDay(), 'is_active' => false]);

        $this->actingAs($viewer)->getJson('/api/v1/announcements')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id);
        $this->getJson('/api/v1/announcements/'.$draft->id)->assertForbidden();
    }

    public function test_announcements_managers_can_view_drafts_and_create_trusted_drafts(): void
    {
        $manager = $this->user('announcements.manage');

        $response = $this->actingAs($manager)->postJson('/api/v1/announcements', [
            'title' => 'Notice', 'content' => 'Office notice.', 'published_at' => '2026-01-01', 'is_active' => false,
            'created_by_user_id' => $this->user()->id, 'attachment_path' => 'private/notice.pdf',
        ]);

        $response->assertCreated()->assertJsonPath('data.published_at', null)->assertJsonPath('data.is_active', true)->assertJsonPath('data.created_by_user_id', $manager->id);
        $id = $response->json('data.id');
        $this->getJson('/api/v1/announcements')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->assertDatabaseHas('announcements', ['id' => $id, 'created_by_user_id' => $manager->id, 'published_at' => null, 'is_active' => true]);
        $this->assertSafeResource($response->json('data'));
    }

    public function test_announcements_update_uses_form_request_and_cannot_edit_published_record(): void
    {
        $manager = $this->user('announcements.manage');
        $announcement = Announcement::query()->create(['title' => 'Draft', 'content' => 'Original.']);
        $payload = ['title' => 'Edited', 'content' => 'Revised.', 'priority' => 'important', 'show_on_dashboard' => false];

        $this->actingAs($manager)->putJson('/api/v1/announcements/'.$announcement->id, $payload)
            ->assertOk()->assertJsonPath('data.title', 'Edited')->assertJsonPath('data.show_on_dashboard', false);
        $this->assertSame('Revised.', $announcement->fresh()->content);
        $announcement->update(['published_at' => now()->subDay()]);

        $this->putJson('/api/v1/announcements/'.$announcement->id, array_replace($payload, ['title' => 'Forbidden edit']))->assertConflict();

        $this->assertSame('Edited', $announcement->fresh()->title);
    }

    public function test_announcements_schedule_reschedule_and_cancel_schedule_use_service(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(12, 0));
        $manager = $this->user('announcements.manage');
        $announcement = Announcement::query()->create(['title' => 'Draft', 'content' => 'Schedule me.']);
        $url = '/api/v1/announcements/'.$announcement->id;

        $this->actingAs($manager)->postJson($url.'/schedule', ['scheduled_at' => '2026-10-04 12:00:00', 'expires_at' => '2026-10-06 12:00:00'])->assertOk();
        $this->assertSame('2026-10-04 12:00:00', $announcement->fresh()->published_at->format('Y-m-d H:i:s'));
        $this->postJson($url.'/reschedule', ['scheduled_at' => '2026-10-05 12:00:00', 'expires_at' => '2026-10-07 12:00:00'])->assertOk();
        $this->assertSame('2026-10-05 12:00:00', $announcement->fresh()->published_at->format('Y-m-d H:i:s'));
        $this->postJson($url.'/cancel-schedule')->assertOk()->assertJsonPath('data.published_at', null)->assertJsonPath('data.expires_at', null);
        $this->assertNull($announcement->fresh()->published_at);
    }

    public function test_announcements_publish_deactivate_and_reactivate_use_service(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(12, 0));
        $manager = $this->user('announcements.manage');
        $announcement = Announcement::query()->create(['title' => 'Draft', 'content' => 'Publish me.']);
        $url = '/api/v1/announcements/'.$announcement->id;

        $this->actingAs($manager)->postJson($url.'/publish')->assertOk();
        $this->assertTrue($announcement->fresh()->published_at->equalTo(now()));
        $this->postJson($url.'/deactivate')->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertFalse($announcement->fresh()->is_active);
        $this->postJson($url.'/reactivate')->assertOk()->assertJsonPath('data.is_active', true);
        $this->assertTrue($announcement->fresh()->is_active);
    }

    public function test_announcements_update_preserves_omitted_attachment_and_clears_explicit_null(): void
    {
        $manager = $this->user('announcements.manage');
        $announcement = Announcement::query()->create([
            'title' => 'Draft', 'content' => 'Original.', 'attachment_path' => 'private/announcement.pdf',
        ]);
        $payload = ['title' => 'Updated', 'content' => 'Revised.', 'priority' => 'normal', 'show_on_dashboard' => true];
        $url = '/api/v1/announcements/'.$announcement->id;

        $this->actingAs($manager)->putJson($url, $payload)->assertOk();

        $this->assertSame('private/announcement.pdf', $announcement->fresh()->attachment_path);

        $this->putJson($url, $payload + ['attachment_path' => null])->assertOk();

        $this->assertNull($announcement->fresh()->attachment_path);
    }

    public function test_announcements_dashboard_excludes_disabled_dashboard_items(): void
    {
        $viewer = $this->user('announcements.view');
        $visible = Announcement::query()->create(['title' => 'Dashboard', 'content' => 'Shown.', 'published_at' => now()->subDay(), 'show_on_dashboard' => true]);
        Announcement::query()->create(['title' => 'Hidden', 'content' => 'Not on dashboard.', 'published_at' => now()->subDay(), 'show_on_dashboard' => false]);

        $this->actingAs($viewer)->getJson('/api/v1/announcements/dashboard')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id);
    }

    public static function endpoints(): array
    {
        $cases = ['SystemSettings endpoints' => ['system-settings'], 'Announcements endpoints' => ['announcements']];

        foreach (self::workflows() as $label => [, $path]) {
            $cases[$label] = [$path];
        }

        return $cases;
    }

    public static function workflows(): array
    {
        return [
            'EmployeeProfileChange workflow' => ['EmployeeProfileChange', 'employee-profile-change-requests', 'profile-changes'],
            'AttendanceCorrection workflow' => ['AttendanceCorrection', 'attendance-correction-requests', 'attendance-corrections'],
            'Leave workflow' => ['Leave', 'leave-requests', 'leave'],
            'Cto workflow' => ['Cto', 'cto-requests', 'cto'],
            'OfficialBusiness workflow' => ['OfficialBusiness', 'official-business-requests', 'official-business'],
            'TravelOrder workflow' => ['TravelOrder', 'travel-order-requests', 'travel-orders'],
            'Overtime workflow' => ['Overtime', 'overtime-requests', 'overtime'],
        ];
    }

    public static function supervisedWorkflows(): array
    {
        return array_filter(self::workflows(), static fn (array $workflow): bool => $workflow[0] !== 'EmployeeProfileChange');
    }

    private static function model(string $module): string
    {
        return 'App\\Models\\'.$module.'Request';
    }

    private static function approval(string $module): array
    {
        return match ($module) {
            'Cto', 'Overtime' => ['approved_minutes' => 30, 'remarks' => 'Approved.'],
            'Leave' => ['approved_units' => '0.50', 'remarks' => 'Approved.'],
            default => ['remarks' => 'Approved.'],
        };
    }

    private function reviewFixture(string $prefix): array
    {
        $reviewer = $this->user($prefix.'.approve');
        $supervisor = $this->employee($reviewer);
        $owner = $this->user($prefix.'.create');

        return [$reviewer, $owner, $this->employee($owner, ['supervisor_employee_id' => $supervisor->id])];
    }

    private function leaveType(): LeaveType
    {
        return LeaveType::query()->create([
            'code' => (string) Str::uuid(), 'name' => 'Unpaid leave', 'is_active' => true,
            'requires_balance' => false, 'allows_partial_day' => true, 'requires_document' => false,
        ]);
    }

    private function submission(string $module, Employee $employee): array
    {
        return match ($module) {
            'EmployeeProfileChange' => ['changes' => ['first_name' => 'Updated'], 'reason' => 'Update profile.'],
            'AttendanceCorrection' => [
                'daily_attendance_id' => DailyAttendance::query()->create([
                    'employee_id' => $employee->id, 'attendance_date' => '2026-10-03',
                    'actual_in' => '2026-10-03 08:00:00', 'actual_out' => '2026-10-03 17:00:00', 'status' => 'present',
                ])->id,
                'attendance_date' => '2026-10-03', 'requested_time_in' => '2026-10-03 09:00:00',
                'requested_time_out' => '2026-10-03 17:00:00', 'reason' => 'Correct scan.',
            ],
            'Leave' => [
                'leave_type_id' => $this->leaveType()->id, 'start_date' => '2026-10-03', 'end_date' => '2026-10-03',
                'day_portion' => 'full_day', 'requested_units' => '1.00', 'reason' => 'Appointment.',
            ],
            'Cto', 'Overtime' => ['start_at' => '2026-10-03 08:00:00', 'end_at' => '2026-10-03 09:00:00', 'reason' => 'Appointment.'],
            default => [
                'start_at' => '2026-10-03 08:00:00', 'end_at' => '2026-10-03 09:00:00',
                'destination' => 'Head office', 'purpose' => 'Official meeting.',
            ],
        };
    }

    private function record(string $module, Employee $employee, User $owner): Model
    {
        $attributes = $this->submission($module, $employee);
        $changes = $attributes['changes'] ?? null;
        unset($attributes['changes']);

        if (in_array($module, ['Cto', 'Overtime'], true)) {
            $attributes['requested_minutes'] = 60;
        }

        $record = self::model($module)::query()->create($attributes + [
            'employee_id' => $employee->id, 'created_by_user_id' => $owner->id, 'status' => 'pending',
        ]);

        if ($record instanceof EmployeeProfileChangeRequest) {
            foreach ($changes as $field => $value) {
                $record->items()->create(['field_name' => $field, 'old_value' => $employee->{$field}, 'new_value' => $value]);
            }
        }

        return $record;
    }

    private function assertSafeResource(array $data): void
    {
        foreach (['document_path', 'attachment_path', 'creator', 'reviewer', 'applier', 'password', 'remember_token'] as $field) {
            $this->assertArrayNotHasKey($field, $data);
        }

        if (isset($data['employee'])) {
            foreach (['personal_email', 'birth_date', 'address_line_1', 'mobile_number', 'user', 'compensations', 'documents'] as $field) {
                $this->assertArrayNotHasKey($field, $data['employee']);
            }
        }
    }
}
