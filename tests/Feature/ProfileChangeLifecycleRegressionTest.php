<?php

namespace Tests\Feature;

use App\Jobs\DeliverHrisNotification;
use App\Models\EmployeeProfileChangeRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class ProfileChangeLifecycleRegressionTest extends FormRequestTestCase
{
    public function test_returns_409_without_creating_request_items_or_notifications_when_all_profile_values_are_unchanged(): void
    {
        $owner = $this->user('profile-changes.create');
        $employee = $this->employee($owner, ['middle_name' => null]);
        Queue::fake([DeliverHrisNotification::class]);

        $this->actingAs($owner)->postJson('/api/v1/employee-profile-change-requests', [
            'changes' => ['first_name' => $employee->first_name, 'middle_name' => null],
        ])->assertConflict()->assertJsonPath('message', 'No actual profile changes were provided.');

        $this->assertDatabaseCount('employee_profile_change_requests', 0);
        $this->assertDatabaseCount('employee_profile_change_items', 0);
        $this->assertSame('Request', $employee->fresh()->first_name);
        Queue::assertNotPushed(DeliverHrisNotification::class);
    }

    public function test_applies_only_changed_fields_and_preserves_nullable_and_date_only_profile_values(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00'));
        $owner = $this->user('profile-changes.create');
        $reviewer = $this->user('profile-changes.approve');
        $employee = $this->employee($owner, ['birth_date' => '1990-02-02', 'middle_name' => 'Original', 'personal_email' => 'old@example.test']);
        $request = $this->submit($owner, [
            'first_name' => 'Request', 'middle_name' => null, 'birth_date' => '1991-03-03', 'personal_email' => 'new@example.test',
        ]);

        $this->assertSame(['middle_name', 'birth_date', 'personal_email'], $request->items()->orderBy('id')->pluck('field_name')->all());
        $this->assertDatabaseHas('employee_profile_change_items', [
            'employee_profile_change_request_id' => $request->id, 'field_name' => 'birth_date',
            'old_value' => $employee->fresh()->getRawOriginal('birth_date'), 'new_value' => '1991-03-03',
        ]);
        $this->actingAs($reviewer)->postJson('/api/v1/employee-profile-change-requests/'.$request->id.'/approve', [])->assertOk();
        $this->assertSame('Original', $employee->fresh()->middle_name);
        Queue::fake([DeliverHrisNotification::class]);

        $this->postJson('/api/v1/employee-profile-change-requests/'.$request->id.'/apply', [
            'applied_by_user_id' => $owner->id, 'changes' => ['first_name' => 'Injected'],
        ])->assertOk()->assertJsonPath('data.status', 'applied')->assertJsonPath('data.applied_by_user_id', $reviewer->id);

        $freshEmployee = $employee->fresh();
        $this->assertSame('Request', $freshEmployee->first_name);
        $this->assertNull($freshEmployee->middle_name);
        $this->assertSame('1991-03-03', $freshEmployee->birth_date->toDateString());
        $this->assertSame('new@example.test', $freshEmployee->personal_email);
        $this->assertSame('2026-10-05 12:00:00', $request->fresh()->applied_at->format('Y-m-d H:i:s'));
        Queue::assertPushed(DeliverHrisNotification::class, fn (DeliverHrisNotification $job): bool => $job->resourceId === $request->id && $job->event === 'applied');
    }

    public function test_returns_409_and_applies_no_fields_from_an_approved_request_after_a_competing_request_changed_one_field(): void
    {
        $owner = $this->user('profile-changes.create');
        $reviewer = $this->user('profile-changes.approve');
        $employee = $this->employee($owner, ['city' => 'Manila']);
        $firstRequest = $this->submit($owner, ['first_name' => 'First approved value']);
        $competingRequest = $this->submit($owner, ['city' => 'Cebu', 'first_name' => 'Competing approved value']);
        $this->actingAs($reviewer)->postJson('/api/v1/employee-profile-change-requests/'.$firstRequest->id.'/approve', [])->assertOk();
        $this->postJson('/api/v1/employee-profile-change-requests/'.$competingRequest->id.'/approve', ['remarks' => 'Approved before the competing application.'])->assertOk();
        $this->postJson('/api/v1/employee-profile-change-requests/'.$firstRequest->id.'/apply', [])->assertOk();
        Queue::fake([DeliverHrisNotification::class]);

        $this->postJson('/api/v1/employee-profile-change-requests/'.$competingRequest->id.'/apply', [])
            ->assertConflict()->assertJsonPath('message', 'Profile field changed after request submission: first_name.');

        $this->assertSame('First approved value', $employee->fresh()->first_name);
        $this->assertSame('Manila', $employee->fresh()->city);
        $fresh = $competingRequest->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($reviewer->id, $fresh->reviewed_by_user_id);
        $this->assertSame('Approved before the competing application.', $fresh->review_remarks);
        $this->assertNull($fresh->applied_at);
        $this->assertNull($fresh->applied_by_user_id);
        $this->assertSame('Cebu', $competingRequest->items()->where('field_name', 'city')->sole()->new_value);
        Queue::assertNotPushed(DeliverHrisNotification::class);
    }

    public function test_returns_409_without_overwriting_later_employee_edits_when_an_applied_request_is_applied_again(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00'));
        $owner = $this->user('profile-changes.create');
        $reviewer = $this->user('profile-changes.approve');
        $employee = $this->employee($owner);
        $request = $this->submit($owner, ['first_name' => 'Approved value']);
        $this->actingAs($reviewer)->postJson('/api/v1/employee-profile-change-requests/'.$request->id.'/approve', [])->assertOk();
        $this->postJson('/api/v1/employee-profile-change-requests/'.$request->id.'/apply', [])->assertOk();
        $employee->update(['first_name' => 'Later edit']);
        $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00'));
        Queue::fake([DeliverHrisNotification::class]);

        $this->postJson('/api/v1/employee-profile-change-requests/'.$request->id.'/apply', [])
            ->assertConflict()->assertJsonPath('message', 'Profile change request must be approved. Current status: applied.');

        $this->assertSame('Later edit', $employee->fresh()->first_name);
        $this->assertSame('applied', $request->fresh()->status);
        $this->assertSame($reviewer->id, $request->fresh()->applied_by_user_id);
        $this->assertSame('2026-10-05 12:00:00', $request->fresh()->applied_at->format('Y-m-d H:i:s'));
        Queue::assertNotPushed(DeliverHrisNotification::class);
    }

    #[DataProvider('unapprovedStates')]
    public function test_returns_409_without_updating_employee_when_application_is_attempted_before_approval_or_after_rejection_or_cancellation(string $state): void
    {
        $owner = $this->user('profile-changes.create');
        $reviewer = $this->user('profile-changes.approve');
        $employee = $this->employee($owner);
        $request = $this->submit($owner, ['first_name' => 'Unapproved value']);
        if ($state === 'rejected') {
            $this->actingAs($reviewer)->postJson('/api/v1/employee-profile-change-requests/'.$request->id.'/reject', [])->assertOk();
        } elseif ($state === 'cancelled') {
            $this->actingAs($owner)->postJson('/api/v1/employee-profile-change-requests/'.$request->id.'/cancel', [])->assertOk();
        }
        Queue::fake([DeliverHrisNotification::class]);

        $this->actingAs($reviewer)->postJson('/api/v1/employee-profile-change-requests/'.$request->id.'/apply', [])
            ->assertConflict()->assertJsonPath('message', 'Profile change request must be approved. Current status: '.$state.'.');

        $this->assertSame('Request', $employee->fresh()->first_name);
        $this->assertSame($state, $request->fresh()->status);
        $this->assertNull($request->fresh()->applied_at);
        $this->assertNull($request->fresh()->applied_by_user_id);
        Queue::assertNotPushed(DeliverHrisNotification::class);
    }

    /** @return array<string, array{string}> */
    public static function unapprovedStates(): array
    {
        return ['pending' => ['pending'], 'rejected' => ['rejected'], 'cancelled' => ['cancelled']];
    }

    /** @param array<string, mixed> $changes */
    private function submit(User $owner, array $changes): EmployeeProfileChangeRequest
    {
        Queue::fake([DeliverHrisNotification::class]);
        $response = $this->actingAs($owner)->postJson('/api/v1/employee-profile-change-requests', ['changes' => $changes])->assertCreated();

        return EmployeeProfileChangeRequest::query()->findOrFail($response->json('data.id'));
    }
}
