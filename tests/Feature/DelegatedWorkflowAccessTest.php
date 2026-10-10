<?php

namespace Tests\Feature;

use App\Models\ApprovalDelegation;
use App\Models\AttendanceCorrectionRequest;
use App\Models\CtoRequest;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OfficialBusinessRequest;
use App\Models\OvertimeRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TravelOrderRequest;
use App\Models\User;
use App\Services\Approval\ApprovalAuthorityResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FormRequestTestCase;

class DelegatedWorkflowAccessTest extends FormRequestTestCase
{
    #[DataProvider('serviceWorkflows')]
    public function test_service_delegated_employee_scope_matches_resolved_authority_for_all_types(string $model, string $type, string $prefix): void
    {
        [$delegate, $supervisor, $target] = $this->fixture($model);
        $employee = $target->employee;
        $inactiveReport = $this->employee(null, ['supervisor_employee_id' => $employee->supervisor_employee_id, 'is_active' => false]);
        $employee->supervisor->update(['is_active' => false]);
        $this->employee();
        $resolver = $this->app->make(ApprovalAuthorityResolver::class);

        $ids = $resolver->delegatedEmployees($delegate, $type)->orderBy('employees.id')->pluck('employees.id')->all();

        $this->assertSame([$employee->id, $inactiveReport->id], $ids);
        $this->assertTrue($resolver->resolve($employee->fresh(), $type)?->is($delegate));
        $this->assertTrue($resolver->resolve($inactiveReport, $type)?->is($delegate));
        $this->assertSame([], $resolver->delegatedEmployees($supervisor, $type)->pluck('employees.id')->all());
    }

    #[DataProvider('serviceBlockedDelegations')]
    public function test_service_delegated_employee_scope_rejects_ineffective_delegations_for_all_types(string $model, string $type, string $prefix, string $condition): void
    {
        [$delegate, $supervisor, $target, $delegation] = $this->fixture($model);
        $this->blockDelegation($condition, $delegate, $supervisor, $delegation, $prefix);
        $resolver = $this->app->make(ApprovalAuthorityResolver::class);

        $ids = $resolver->delegatedEmployees($delegate->fresh(), $type)->pluck('employees.id')->all();

        $this->assertSame([], $ids);
        $this->assertNotSame($delegate->id, $resolver->resolve($target->employee()->firstOrFail(), $type)?->id);
    }

    #[DataProvider('serviceWorkflows')]
    public function test_service_newest_id_resolves_equal_start_time_delegations_consistently(string $model, string $type, string $prefix): void
    {
        [$delegate, $supervisor, $target, $delegation] = $this->fixture($model);
        $newDelegate = $this->roleUser('manager');
        $this->delegate($supervisor, $newDelegate);
        $resolver = $this->app->make(ApprovalAuthorityResolver::class);

        $selected = $resolver->resolveDelegate($supervisor, $type);

        $this->assertTrue($selected?->is($newDelegate));
        $this->assertTrue($resolver->resolve($target->employee, $type)?->is($newDelegate));
        $this->assertSame([], $resolver->delegatedEmployees($delegate, $type)->pluck('employees.id')->all());
        $this->assertSame([$target->employee_id], $resolver->delegatedEmployees($newDelegate, $type)->pluck('employees.id')->all());
    }

    #[DataProvider('serviceWorkflows')]
    public function test_service_scope_accepts_active_direct_permissions_without_roles_for_all_types(string $model, string $type, string $prefix): void
    {
        [$delegate, $supervisor, $target] = $this->fixture($model);
        $permission = Permission::query()->where('code', $prefix.'.approve')->sole();
        $supervisor->roles()->detach();
        $supervisor->permissions()->attach($permission);
        $delegate->roles()->detach();
        $delegate->permissions()->attach($permission);
        $resolver = $this->app->make(ApprovalAuthorityResolver::class);

        $ids = $resolver->delegatedEmployees($delegate->fresh(), $type)->pluck('employees.id')->all();

        $this->assertSame([$target->employee_id], $ids);
        $this->assertTrue($resolver->resolve($target->employee, $type)?->is($delegate));
    }

    public static function serviceWorkflows(): array
    {
        return [
            'leave' => [LeaveRequest::class, ApprovalAuthorityResolver::TYPE_LEAVE, 'leave'],
            'cto' => [CtoRequest::class, ApprovalAuthorityResolver::TYPE_CTO, 'cto'],
            'attendance correction' => [AttendanceCorrectionRequest::class, ApprovalAuthorityResolver::TYPE_ATTENDANCE_CORRECTION, 'attendance-corrections'],
            'official business' => [OfficialBusinessRequest::class, ApprovalAuthorityResolver::TYPE_OFFICIAL_BUSINESS, 'official-business'],
            'travel order' => [TravelOrderRequest::class, ApprovalAuthorityResolver::TYPE_TRAVEL_ORDER, 'travel-orders'],
            'overtime' => [OvertimeRequest::class, ApprovalAuthorityResolver::TYPE_OVERTIME, 'overtime'],
        ];
    }

    public static function serviceBlockedDelegations(): array
    {
        $cases = [];
        $conditions = self::ineffectiveDelegations() + [
            'inactive delegate role' => ['inactive-delegate-role'],
            'inactive delegator role' => ['inactive-delegator-role'],
        ];

        foreach (self::serviceWorkflows() as $type => $workflow) {
            foreach ($conditions as $condition => [$value]) {
                $cases[$type.' / '.$condition] = [...$workflow, $value];
            }
        }

        return $cases;
    }

    public function test_api_lists_requests_for_qualified_delegate_without_an_employee_profile(): void
    {
        [$delegate, $supervisor, $target, $delegation, $delegateEmployee] = $this->fixture(CtoRequest::class);
        $delegateEmployee->delete();

        $this->actingAs($delegate->fresh())->getJson('/api/v1/cto-requests')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
        $this->getJson('/api/v1/cto-requests/'.$target->id)->assertOk();
        $this->getJson('/api/v1/ess/cto-requests')->assertForbidden();
    }

    public function test_api_resolves_delegation_from_active_direct_permissions_without_roles(): void
    {
        [$delegate, $supervisor, $target] = $this->fixture(CtoRequest::class);
        $supervisor->roles()->detach();
        $supervisor->permissions()->attach(Permission::query()->where('code', 'cto.approve')->sole());
        $delegate->roles()->detach();
        $delegate->permissions()->attach(Permission::query()->whereIn('code', ['cto.approve', 'cto.view'])->get());

        $this->actingAs($delegate->fresh())->getJson('/api/v1/cto-requests')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
        $this->getJson('/api/v1/cto-requests/'.$target->id)->assertOk();
        $this->assertTrue(Gate::forUser($delegate->fresh())->allows('approve', $target));
    }

    public static function workflows(): array
    {
        return [
            'leave' => [LeaveRequest::class, 'leave-requests'],
            'cto' => [CtoRequest::class, 'cto-requests'],
            'attendance correction' => [AttendanceCorrectionRequest::class, 'attendance-correction-requests'],
            'official business' => [OfficialBusinessRequest::class, 'official-business-requests'],
            'travel order' => [TravelOrderRequest::class, 'travel-order-requests'],
            'overtime' => [OvertimeRequest::class, 'overtime-requests'],
        ];
    }

    public static function blockedDelegations(): array
    {
        return [
            'unrelated delegator' => ['unrelated', true],
            'expired' => ['expired', true],
            'not started' => ['future', true],
            'inactive delegation' => ['inactive', true],
            'self delegation' => ['self', true],
            'delegate lacks approval permission' => ['unqualified', true],
            'inactive delegate' => ['inactive-delegate', false],
            'delegator lacks approval permission' => ['unqualified-delegator', true],
            'inactive delegator' => ['inactive-delegator', true],
            'newer delegation selects another manager' => ['newer-other', true],
            'newer unqualified delegate cannot fall back to older delegation' => ['newer-unqualified', true],
            'delegate lacks module view permission' => ['no-view', false],
            'inactive approval permission' => ['inactive-permission', true],
        ];
    }

    public static function ineffectiveDelegations(): array
    {
        $cases = self::blockedDelegations();
        unset($cases['delegate lacks module view permission']);

        return $cases;
    }

    /**
     * @param  class-string<Model>  $model
     * @return array{User, User, Model, ApprovalDelegation, Employee}
     */
    private function fixture(string $model): array
    {
        $this->travelTo(now()->setDate(2026, 10, 4)->setTime(12, 0));
        $this->seed(RolePermissionSeeder::class);
        $supervisor = $this->roleUser('supervisor');
        $supervisorEmployee = $this->employee($supervisor);
        $delegate = $this->roleUser('manager');
        $delegateEmployee = $this->employee($delegate);
        $owner = $this->roleUser('employee');
        $targetEmployee = $this->employee($owner, ['supervisor_employee_id' => $supervisorEmployee->id]);
        $target = $this->record($model, $targetEmployee);
        $delegation = $this->delegate($supervisor, $delegate);

        return [$delegate, $supervisor, $target, $delegation, $delegateEmployee];
    }

    private function roleUser(string $code): User
    {
        $user = $this->user();
        $user->roles()->attach(Role::query()->where('code', $code)->sole());

        return $user;
    }

    private function delegate(User $supervisor, User $delegate): ApprovalDelegation
    {
        return ApprovalDelegation::query()->create([
            'delegator_user_id' => $supervisor->id,
            'delegate_user_id' => $delegate->id,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addDay(),
            'is_active' => true,
            'created_by_user_id' => $supervisor->id,
        ]);
    }

    private function blockDelegation(string $condition, User $delegate, User $supervisor, ApprovalDelegation $delegation, string $prefix = 'cto'): void
    {
        match ($condition) {
            'unrelated' => $delegation->update(['delegator_user_id' => $this->roleUser('supervisor')->id]),
            'expired' => $delegation->update(['ends_at' => now()->subSecond()]),
            'future' => $delegation->update(['starts_at' => now()->addSecond()]),
            'inactive' => $delegation->update(['is_active' => false]),
            'self' => $delegation->update(['delegate_user_id' => $supervisor->id]),
            'unqualified' => $delegate->roles()->sync([Role::query()->where('code', 'employee')->sole()->id]),
            'inactive-delegate' => $delegate->update(['is_active' => false]),
            'unqualified-delegator' => $supervisor->roles()->sync([Role::query()->where('code', 'employee')->sole()->id]),
            'inactive-delegator' => $supervisor->update(['is_active' => false]),
            'newer-other' => $this->delegate($supervisor, $this->roleUser('manager'))->update(['starts_at' => now()->subHour()]),
            'newer-unqualified' => $this->delegate($supervisor, $this->roleUser('employee'))->update(['starts_at' => now()->subHour()]),
            'no-view' => Role::query()->where('code', 'manager')->sole()->permissions()->detach(Permission::query()->where('code', $prefix.'.view')->sole()->id),
            'inactive-permission' => Permission::query()->where('code', $prefix.'.approve')->sole()->update(['is_active' => false]),
            'inactive-delegate-role' => Role::query()->where('code', 'manager')->sole()->update(['is_active' => false]),
            'inactive-delegator-role' => Role::query()->where('code', 'supervisor')->sole()->update(['is_active' => false]),
        };
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function record(string $model, Employee $employee): Model
    {
        $attributes = match ($model) {
            AttendanceCorrectionRequest::class => [
                'attendance_date' => '2026-10-03',
                'requested_time_in' => '2026-10-03 08:00:00',
                'requested_time_out' => '2026-10-03 17:00:00',
                'reason' => 'Correct scans.',
            ],
            LeaveRequest::class => [
                'leave_type_id' => LeaveType::query()->create([
                    'code' => (string) Str::uuid(), 'name' => 'Unpaid leave', 'is_active' => true,
                    'requires_balance' => false, 'allows_partial_day' => true, 'requires_document' => false,
                ])->id,
                'start_date' => '2026-10-03', 'end_date' => '2026-10-03',
                'day_portion' => 'full_day', 'requested_units' => '1.00', 'reason' => 'Appointment.',
            ],
            CtoRequest::class, OvertimeRequest::class => [
                'start_at' => '2026-10-03 08:00:00', 'end_at' => '2026-10-03 09:00:00',
                'requested_minutes' => 60, 'reason' => 'Appointment.',
            ],
            default => [
                'start_at' => '2026-10-03 08:00:00', 'end_at' => '2026-10-03 09:00:00',
                'destination' => 'Head office', 'purpose' => 'Official meeting.',
            ],
        };

        return $model::query()->create($attributes + [
            'employee_id' => $employee->id, 'created_by_user_id' => $employee->user_id, 'status' => 'pending',
        ]);
    }

    #[DataProvider('workflows')]
    public function test_api_lists_delegated_requests_alongside_own_and_direct_team_records(string $model, string $path): void
    {
        [$delegate, $supervisor, $target, $delegation, $delegateEmployee] = $this->fixture($model);
        $own = $this->record($model, $delegateEmployee);
        $report = $this->record($model, $this->employee(null, ['supervisor_employee_id' => $delegateEmployee->id]));
        $unrelated = $this->record($model, $this->employee());

        $response = $this->actingAs($delegate)->getJson('/api/v1/'.$path);

        $response->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame([$report->id, $own->id, $target->id], array_column($response->json('data'), 'id'));
        $this->getJson('/api/v1/'.$path.'/'.$target->id)->assertOk()->assertJsonPath('data.id', $target->id);
        $this->getJson('/api/v1/'.$path.'?employee_id='.$target->employee_id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
        $this->getJson('/api/v1/'.$path.'/'.$unrelated->id)->assertForbidden();
    }

    #[DataProvider('blockedDelegations')]
    public function test_api_does_not_list_or_show_requests_through_ineffective_delegations(string $condition, bool $canList): void
    {
        [$delegate, $supervisor, $target, $delegation] = $this->fixture(CtoRequest::class);
        $this->blockDelegation($condition, $delegate, $supervisor, $delegation);
        $this->actingAs($delegate->fresh());

        $response = $this->getJson('/api/v1/cto-requests');

        if ($canList) {
            $response->assertOk()->assertJsonCount(0, 'data');
        } else {
            $response->assertForbidden();
        }

        $this->getJson('/api/v1/cto-requests/'.$target->id)->assertForbidden();
    }

    #[DataProvider('workflows')]
    public function test_ess_remains_own_only_for_effective_delegates(string $model, string $path): void
    {
        [$delegate, $supervisor, $target, $delegation, $delegateEmployee] = $this->fixture($model);
        $own = $this->record($model, $delegateEmployee);
        $this->record($model, $this->employee(null, ['supervisor_employee_id' => $delegateEmployee->id]));

        $this->actingAs($delegate)->getJson('/api/v1/ess/'.$path)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson('/api/v1/ess/'.$path.'?employee_id='.$target->employee_id)
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_api_delegated_read_matches_current_authority_for_reviewed_records_and_revocation(): void
    {
        [$delegate, $supervisor, $target, $delegation] = $this->fixture(CtoRequest::class);
        $target->update(['status' => 'approved', 'reviewed_by_user_id' => $delegate->id, 'reviewed_at' => now()]);
        $this->actingAs($delegate);

        $this->getJson('/api/v1/cto-requests')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
        $this->getJson('/api/v1/cto-requests/'.$target->id)->assertOk();
        $this->assertTrue(Gate::forUser($delegate)->allows('view', $target));

        $delegation->update(['is_active' => false]);

        $this->getJson('/api/v1/cto-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/cto-requests/'.$target->id)->assertForbidden();
        $this->assertFalse(Gate::forUser($delegate)->allows('view', $target));
    }

    public function test_api_delegated_read_includes_exact_start_and_end_boundaries(): void
    {
        [$delegate, $supervisor, $target, $delegation] = $this->fixture(CtoRequest::class);
        $delegation->update(['starts_at' => now(), 'ends_at' => now()]);

        $this->actingAs($delegate)->getJson('/api/v1/cto-requests')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
        $this->assertTrue(Gate::forUser($delegate)->allows('view', $target));

        $this->travel(1)->seconds();

        $this->getJson('/api/v1/cto-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->assertFalse(Gate::forUser($delegate)->allows('view', $target));
    }

    #[DataProvider('workflows')]
    public function test_policy_preserves_original_supervisor_team_read_after_authority_moves(string $model, string $path): void
    {
        [$delegate, $supervisor, $target] = $this->fixture($model);

        $gate = Gate::forUser($supervisor);

        $this->assertTrue($gate->allows('view', $target));
        $this->assertFalse($gate->allows('approve', $target));
        $this->assertFalse($gate->allows('reject', $target));
    }

    #[DataProvider('workflows')]
    public function test_policy_allows_qualified_delegate_without_an_employee_profile(string $model, string $path): void
    {
        [$delegate, $supervisor, $target, $delegation, $delegateEmployee] = $this->fixture($model);
        $delegateEmployee->delete();

        $gate = Gate::forUser($delegate->fresh());

        $this->assertTrue($gate->allows('view', $target));
        $this->assertTrue($gate->allows('approve', $target));
    }

    #[DataProvider('ineffectiveDelegations')]
    public function test_policy_does_not_broaden_access_for_ineffective_delegations(string $condition, bool $canList): void
    {
        [$delegate, $supervisor, $target, $delegation] = $this->fixture(CtoRequest::class);
        $this->blockDelegation($condition, $delegate, $supervisor, $delegation);

        $gate = Gate::forUser($delegate->fresh());

        $this->assertFalse($gate->allows('view', $target));
        $this->assertFalse($gate->allows('approve', $target));
        $this->assertFalse($gate->allows('reject', $target));
    }

    public function test_policy_requires_view_permission_even_for_an_effective_approver(): void
    {
        [$delegate, $supervisor, $target] = $this->fixture(CtoRequest::class);
        $delegate->roles()->detach();
        $delegate->permissions()->attach(Permission::query()->where('code', 'cto.approve')->sole());

        $gate = Gate::forUser($delegate->fresh());

        $this->assertTrue($gate->allows('approve', $target));
        $this->assertTrue($gate->allows('reject', $target));
        $this->assertFalse($gate->allows('view', $target));
        $this->assertFalse($gate->allows('viewAny', CtoRequest::class));
    }

    #[DataProvider('workflows')]
    public function test_policy_allows_effective_delegate_to_read_and_review_outside_their_team(string $model, string $path): void
    {
        [$delegate, $supervisor, $target] = $this->fixture($model);

        $gate = Gate::forUser($delegate);

        $this->assertTrue($gate->allows('view', $target));
        $this->assertTrue($gate->allows('approve', $target));
        $this->assertTrue($gate->allows('reject', $target));
        $this->assertFalse(Gate::forUser($supervisor)->allows('approve', $target));
    }
}
