<?php

namespace App\Services\Workspace;

use App\Models\AttendanceCorrectionRequest;
use App\Models\CtoRequest;
use App\Models\Employee;
use App\Models\EmployeeProfileChangeRequest;
use App\Models\LeaveRequest;
use App\Models\OfficialBusinessRequest;
use App\Models\OvertimeRequest;
use App\Models\TravelOrderRequest;
use App\Models\User;
use App\Services\Approval\ApprovalAuthorityResolver;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

class WorkspaceReadScope
{
    public function __construct(private readonly ApprovalAuthorityResolver $authority) {}

    /** @return array<string, array<string, mixed>> */
    public function workflows(): array
    {
        return [
            'leave' => ['model' => LeaveRequest::class, 'module' => 'leave', 'label' => 'Leave', 'permission' => 'leave', 'authority' => ApprovalAuthorityResolver::TYPE_LEAVE, 'date_kind' => 'date'],
            'cto' => ['model' => CtoRequest::class, 'module' => 'cto', 'label' => 'CTO', 'permission' => 'cto', 'authority' => ApprovalAuthorityResolver::TYPE_CTO, 'date_kind' => 'time'],
            'overtime' => ['model' => OvertimeRequest::class, 'module' => 'overtime', 'label' => 'Overtime', 'permission' => 'overtime', 'authority' => ApprovalAuthorityResolver::TYPE_OVERTIME, 'date_kind' => 'time'],
            'official_business' => ['model' => OfficialBusinessRequest::class, 'module' => 'official-business', 'label' => 'Official business', 'permission' => 'official-business', 'authority' => ApprovalAuthorityResolver::TYPE_OFFICIAL_BUSINESS, 'date_kind' => 'time'],
            'travel_order' => ['model' => TravelOrderRequest::class, 'module' => 'travel-orders', 'label' => 'Travel order', 'permission' => 'travel-orders', 'authority' => ApprovalAuthorityResolver::TYPE_TRAVEL_ORDER, 'date_kind' => 'time'],
            'attendance_correction' => ['model' => AttendanceCorrectionRequest::class, 'module' => 'attendance-corrections', 'label' => 'Attendance correction', 'permission' => 'attendance-corrections', 'authority' => ApprovalAuthorityResolver::TYPE_ATTENDANCE_CORRECTION, 'date_kind' => 'attendance'],
            'profile_changes' => ['model' => EmployeeProfileChangeRequest::class, 'module' => 'profile-changes', 'label' => 'Profile change', 'permission' => 'profile-changes', 'authority' => null, 'date_kind' => 'submitted'],
        ];
    }

    /** @return array<string, mixed> */
    public function context(User $user, array $filters): array
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $employeeId = $user->employee()->value('id');
        $defaultVisible = $user->hasPermission('employees.view')
            || $user->hasPermission('employees.view-team')
            || $user->hasPermission('schedules.assign');

        foreach ($this->workflows() as $workflow) {
            $defaultVisible = $defaultVisible || $user->hasPermission($workflow['permission'].'.approve');
        }

        $scope = $filters['scope'] ?? ($employeeId !== null && ! $defaultVisible ? 'mine' : 'visible');

        if ($scope === 'mine' && $employeeId === null) {
            throw new AuthorizationException('An employee profile is required for my workspace.');
        }

        $start = CarbonImmutable::parse($filters['start'] ?? $today->startOfMonth()->toDateString(), config('app.timezone'))->startOfDay();
        $end = CarbonImmutable::parse($filters['end'] ?? $today->endOfMonth()->toDateString(), config('app.timezone'))->startOfDay();

        return [
            'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'end_exclusive' => $end->addDay()->toDateString(), 'timezone' => config('app.timezone')],
            'today' => $today->toDateString(),
            'scope' => $scope,
            'available_scopes' => $employeeId === null ? ['visible'] : ['mine', 'visible'],
        ];
    }

    public function employees(User $user, string $scope): Builder
    {
        $query = Employee::query();

        if ($scope === 'visible' && $user->hasPermission('employees.view')) {
            return $query;
        }

        $employeeId = $user->employee()->value('id');

        return $query->where(function (Builder $employees) use ($user, $scope, $employeeId): void {
            $employees->where('id', $employeeId ?? 0);

            if ($scope === 'visible' && $employeeId !== null && $user->hasPermission('employees.view-team')) {
                $employees->orWhere('supervisor_employee_id', $employeeId);
            }
        });
    }

    public function records(User $user, Builder $query, string $scope, bool $all = false, bool $includeTeam = true, ?string $authorityType = null): Builder
    {
        if ($scope === 'visible' && $all) {
            return $query;
        }

        $employeeId = $user->employee()->value('id');

        return $query->whereIn($query->getModel()->qualifyColumn('employee_id'), Employee::query()->select('employees.id')
            ->where(function (Builder $employees) use ($user, $scope, $employeeId, $includeTeam, $authorityType): void {
                $employees->where('employees.id', $employeeId ?? 0);

                if ($scope === 'visible' && $includeTeam && $employeeId !== null && $user->hasPermission('employees.view-team')) {
                    $employees->orWhere('supervisor_employee_id', $employeeId);
                }

                if ($scope === 'visible' && $authorityType !== null) {
                    $employees->orWhereIn('employees.id', $this->authority->delegatedEmployees($user, $authorityType)->select('employees.id'));
                }
            }));
    }

    /** @param array<string, mixed> $workflow */
    public function workflowQuery(User $user, array $workflow, string $scope): Builder
    {
        return $this->records($user, $workflow['model']::query(), $scope, $user->hasPermission('employees.view'), $workflow['authority'] !== null, $workflow['authority']);
    }

    /** @param array<string, mixed> $workflow */
    public function reviewQuery(User $user, array $workflow, string $scope): Builder
    {
        $query = $this->workflowQuery($user, $workflow, $scope)->where('status', 'pending');

        if (! $user->hasPermission($workflow['permission'].'.view') || ! $user->hasPermission($workflow['permission'].'.approve')) {
            return $query->whereRaw('1 = 0');
        }

        if ($workflow['authority'] === null) {
            return $query;
        }

        $retainsDirectAuthority = $this->authority->resolveDelegate($user, $workflow['authority']) === null;

        return $query->whereIn('employee_id', Employee::query()->select('employees.id')->where(function (Builder $employees) use ($user, $workflow, $retainsDirectAuthority): void {
            $employees->whereIn('employees.id', $this->authority->delegatedEmployees($user, $workflow['authority'])->select('employees.id'));

            if ($retainsDirectAuthority) {
                $employees->orWhereHas('supervisor', fn (Builder $supervisors) => $supervisors->where('user_id', $user->id));
            }
        }));
    }

    public function inRange(Builder $query, string $kind, array $range): Builder
    {
        return match ($kind) {
            'date' => $query->whereDate('start_date', '<=', $range['end'])->whereDate('end_date', '>=', $range['start']),
            'time' => $query->where('start_at', '<', $range['end_exclusive'].' 00:00:00')->where('end_at', '>', $range['start'].' 00:00:00'),
            'attendance' => $query->whereDate('attendance_date', '>=', $range['start'])->whereDate('attendance_date', '<=', $range['end']),
            default => $query->where('created_at', '>=', $range['start'].' 00:00:00')->where('created_at', '<', $range['end_exclusive'].' 00:00:00'),
        };
    }

    /** @return array{id:int, name:string, employee_number:string}|null */
    public function employeeLabel(?Employee $employee): ?array
    {
        if ($employee === null) {
            return null;
        }

        return ['id' => $employee->id, 'name' => trim($employee->first_name.' '.$employee->last_name), 'employee_number' => $employee->employee_number];
    }
}
