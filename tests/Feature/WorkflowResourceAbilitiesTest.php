<?php

namespace Tests\Feature;

use App\Models\ApprovalDelegation;
use App\Models\Employee;
use App\Models\EmployeeProfileChangeRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\FormRequestTestCase;

class WorkflowResourceAbilitiesTest extends FormRequestTestCase
{
    #[DataProvider('supervisedWorkflows')]
    public function test_detail_exposes_direct_supervisor_review_actions(string $module, string $path, string $prefix): void
    {
        [$supervisor, $owner, $employee] = $this->supervisedFixture($prefix);
        $record = $this->record($module, $employee, $owner);

        $this->actingAs($supervisor)->getJson('/api/v1/'.$path.'/'.$record->id)
            ->assertOk()->assertJsonPath('data.abilities', ['approve' => true, 'reject' => true, 'cancel' => false]);
    }

    #[DataProvider('supervisedWorkflows')]
    public function test_detail_does_not_mistake_global_hr_permissions_for_review_authority(string $module, string $path, string $prefix): void
    {
        [$supervisor, $owner, $employee] = $this->supervisedFixture($prefix);
        $hr = $this->user($prefix.'.view', $prefix.'.approve', 'employees.view');
        $record = $this->record($module, $employee, $owner);

        $this->actingAs($hr)->getJson('/api/v1/'.$path.'/'.$record->id)
            ->assertOk()->assertJsonPath('data.abilities', ['approve' => false, 'reject' => false, 'cancel' => false]);
    }

    #[DataProvider('workflows')]
    public function test_detail_exposes_cancel_for_pending_owner_requests(string $module, string $path, string $prefix): void
    {
        $owner = $this->user($prefix.'.view', $prefix.'.create');
        $record = $this->record($module, $this->employee($owner), $owner);
        $expected = ['approve' => false, 'reject' => false, 'cancel' => true];

        if ($module === 'EmployeeProfileChange') {
            $expected['apply'] = false;
        }

        $this->actingAs($owner)->getJson('/api/v1/'.$path.'/'.$record->id)
            ->assertOk()->assertJsonPath('data.abilities', $expected);
    }

    #[DataProvider('supervisedWorkflows')]
    public function test_detail_exposes_effective_delegate_review_actions_outside_their_team(string $module, string $path, string $prefix): void
    {
        [$supervisor, $owner, $employee] = $this->supervisedFixture($prefix);
        $delegate = $this->user($prefix.'.view', $prefix.'.approve');
        $this->employee($delegate);
        $this->delegation($supervisor, $delegate);
        $record = $this->record($module, $employee, $owner);

        $this->actingAs($delegate)->getJson('/api/v1/'.$path.'/'.$record->id)
            ->assertOk()->assertJsonPath('data.abilities', ['approve' => true, 'reject' => true, 'cancel' => false]);
    }

    #[DataProvider('ineffectiveDelegations')]
    public function test_detail_uses_current_delegation_validity_even_with_global_view_access(string $module, string $path, string $prefix, string $condition): void
    {
        [$supervisor, $owner, $employee] = $this->supervisedFixture($prefix);
        $delegate = $this->user($prefix.'.view', $prefix.'.approve', 'employees.view');
        $delegation = $this->delegation($supervisor, $delegate);
        $delegation->update($condition === 'expired'
            ? ['ends_at' => now()->subSecond()]
            : ['is_active' => false]);
        $record = $this->record($module, $employee, $owner);

        $this->actingAs($delegate)->getJson('/api/v1/'.$path.'/'.$record->id)
            ->assertOk()->assertJsonPath('data.abilities', ['approve' => false, 'reject' => false, 'cancel' => false]);
    }

    #[DataProvider('nonPendingWorkflows')]
    public function test_detail_disables_review_and_cancel_after_pending_even_for_an_authorized_actor(string $module, string $path, string $prefix, string $status): void
    {
        [$supervisor, $owner, $employee] = $this->supervisedFixture($prefix);
        $record = $this->record($module, $employee, $supervisor);
        $record->update(['status' => $status]);

        $this->actingAs($supervisor)->getJson('/api/v1/'.$path.'/'.$record->id)
            ->assertOk()->assertJsonPath('data.abilities', ['approve' => false, 'reject' => false, 'cancel' => false]);
    }

    #[TestWith(['pending', true, false])]
    #[TestWith(['approved', false, true])]
    #[TestWith(['applied', false, false])]
    #[TestWith(['rejected', false, false])]
    #[TestWith(['cancelled', false, false])]
    public function test_profile_change_apply_requires_approved_state(string $status, bool $review, bool $apply): void
    {
        $hr = $this->user('profile-changes.view', 'profile-changes.approve', 'employees.view');
        $owner = $this->user('profile-changes.create');
        $record = $this->record('EmployeeProfileChange', $this->employee($owner), $owner);
        $record->update(['status' => $status]);

        $this->actingAs($hr)->getJson('/api/v1/employee-profile-change-requests/'.$record->id)
            ->assertOk()->assertJsonPath('data.abilities', ['approve' => $review, 'reject' => $review, 'cancel' => false, 'apply' => $apply]);
    }

    public function test_profile_change_owner_cannot_apply_an_approved_request_without_review_permission(): void
    {
        $owner = $this->user('profile-changes.view', 'profile-changes.create');
        $record = $this->record('EmployeeProfileChange', $this->employee($owner), $owner);
        $record->update(['status' => 'approved']);

        $this->actingAs($owner)->getJson('/api/v1/employee-profile-change-requests/'.$record->id)
            ->assertOk()->assertJsonPath('data.abilities', ['approve' => false, 'reject' => false, 'cancel' => false, 'apply' => false]);
    }

    #[DataProvider('workflows')]
    public function test_lists_and_ess_omit_abilities_without_evaluating_per_record_action_policies(string $module, string $path, string $prefix): void
    {
        $owner = $this->user($prefix.'.view', $prefix.'.create', $prefix.'.approve');
        $employee = $this->employee($owner);
        $this->record($module, $employee, $owner);
        $this->record($module, $employee, $owner);
        $actions = [];
        Gate::after(function (User $user, string $ability) use (&$actions): void {
            if (in_array($ability, ['approve', 'reject', 'cancel', 'apply'], true)) {
                $actions[] = $ability;
            }
        });

        $this->actingAs($owner)->getJson('/api/v1/'.$path)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.abilities')->assertJsonMissingPath('data.1.abilities');
        $this->getJson('/api/v1/ess/'.$path)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.abilities')->assertJsonMissingPath('data.1.abilities');

        $this->assertSame([], $actions);
    }

    #[DataProvider('workflows')]
    public function test_submission_and_action_responses_do_not_include_detail_only_abilities(string $module, string $path, string $prefix): void
    {
        $owner = $this->user($prefix.'.view', $prefix.'.create');
        $this->employee($owner);

        $submitted = $this->actingAs($owner)->postJson('/api/v1/'.$path, $this->submission($module));

        $submitted->assertCreated()->assertJsonMissingPath('data.abilities');
        $record = self::model($module)::query()->findOrFail($submitted->json('data.id'));
        $this->postJson('/api/v1/'.$path.'/'.$record->id.'/cancel', ['reason' => 'No longer needed.'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonMissingPath('data.abilities');
        $this->assertSame('cancelled', $record->fresh()->status);
    }

    public static function workflows(): array
    {
        return [
            'profile change' => ['EmployeeProfileChange', 'employee-profile-change-requests', 'profile-changes'],
            'attendance correction' => ['AttendanceCorrection', 'attendance-correction-requests', 'attendance-corrections'],
            'leave' => ['Leave', 'leave-requests', 'leave'],
            'cto' => ['Cto', 'cto-requests', 'cto'],
            'official business' => ['OfficialBusiness', 'official-business-requests', 'official-business'],
            'travel order' => ['TravelOrder', 'travel-order-requests', 'travel-orders'],
            'overtime' => ['Overtime', 'overtime-requests', 'overtime'],
        ];
    }

    public static function supervisedWorkflows(): array
    {
        $workflows = self::workflows();
        unset($workflows['profile change']);

        return $workflows;
    }

    public static function ineffectiveDelegations(): array
    {
        $cases = [];

        foreach (self::supervisedWorkflows() as $name => $workflow) {
            foreach (['expired', 'revoked'] as $condition) {
                $cases[$name.' / '.$condition] = [...$workflow, $condition];
            }
        }

        return $cases;
    }

    public static function nonPendingWorkflows(): array
    {
        $cases = [];

        foreach (self::supervisedWorkflows() as $name => $workflow) {
            foreach (['approved', 'rejected', 'cancelled'] as $status) {
                $cases[$name.' / '.$status] = [...$workflow, $status];
            }
        }

        return $cases;
    }

    private function supervisedFixture(string $prefix): array
    {
        $this->travelTo(now()->setDate(2026, 10, 4)->setTime(12, 0));
        $supervisor = $this->user($prefix.'.view', $prefix.'.approve', $prefix.'.create');
        $supervisorEmployee = $this->employee($supervisor);
        $owner = $this->user($prefix.'.create');
        $employee = $this->employee($owner, ['supervisor_employee_id' => $supervisorEmployee->id]);

        return [$supervisor, $owner, $employee];
    }

    private function delegation(User $supervisor, User $delegate): ApprovalDelegation
    {
        return ApprovalDelegation::query()->create([
            'delegator_user_id' => $supervisor->id, 'delegate_user_id' => $delegate->id,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
            'is_active' => true, 'created_by_user_id' => $supervisor->id,
        ]);
    }

    /**
     * @return class-string<Model>
     */
    private static function model(string $module): string
    {
        return 'App\\Models\\'.$module.'Request';
    }

    private function submission(string $module): array
    {
        return match ($module) {
            'EmployeeProfileChange' => ['changes' => ['first_name' => 'Updated'], 'reason' => 'Update profile.'],
            'AttendanceCorrection' => [
                'attendance_date' => '2026-10-03', 'requested_time_in' => '2026-10-03 08:00:00',
                'requested_time_out' => '2026-10-03 17:00:00', 'reason' => 'Correct scan.',
            ],
            'Leave' => [
                'leave_type_id' => LeaveType::query()->create([
                    'code' => (string) Str::uuid(), 'name' => 'Unpaid leave', 'is_active' => true,
                    'requires_balance' => false, 'allows_partial_day' => true, 'requires_document' => false,
                ])->id,
                'start_date' => '2026-10-03', 'end_date' => '2026-10-03',
                'day_portion' => 'full_day', 'requested_units' => '1.00', 'reason' => 'Appointment.',
            ],
            'Cto', 'Overtime' => ['start_at' => '2026-10-03 08:00:00', 'end_at' => '2026-10-03 09:00:00', 'reason' => 'Appointment.'],
            default => [
                'start_at' => '2026-10-03 08:00:00', 'end_at' => '2026-10-03 09:00:00',
                'destination' => 'Head office', 'purpose' => 'Official meeting.',
            ],
        };
    }

    private function record(string $module, Employee $employee, User $creator): Model
    {
        $attributes = $this->submission($module);
        unset($attributes['changes']);

        if (in_array($module, ['Cto', 'Overtime'], true)) {
            $attributes['requested_minutes'] = 60;
        }

        $record = self::model($module)::query()->create($attributes + [
            'employee_id' => $employee->id, 'created_by_user_id' => $creator->id, 'status' => 'pending',
        ]);

        if ($record instanceof EmployeeProfileChangeRequest) {
            $record->items()->create(['field_name' => 'first_name', 'old_value' => $employee->first_name, 'new_value' => 'Updated']);
        }

        return $record;
    }
}
