<?php

namespace App\Services\Workspace;

use App\Models\DailyAttendance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DashboardService
{
    public function __construct(private readonly WorkspaceReadScope $visibility) {}

    /** @return array<string, mixed> */
    public function get(User $user, array $filters = []): array
    {
        $context = $this->visibility->context($user, $filters);
        $requests = [];
        $approvalTypes = [];
        $approvalItems = [];

        foreach ($this->visibility->workflows() as $type => $workflow) {
            if (! $user->hasPermission($workflow['permission'].'.view')) {
                continue;
            }

            $requestQuery = $this->visibility->inRange($this->visibility->workflowQuery($user, $workflow, $context['scope']), $workflow['date_kind'], $context['range']);
            $statuses = $this->statuses($requestQuery);
            $requests[] = ['type' => $type, 'module' => $workflow['module'], 'label' => $workflow['label'], 'total' => array_sum($statuses), 'by_status' => $statuses];

            if (! $user->hasPermission($workflow['permission'].'.approve')) {
                continue;
            }

            $reviewQuery = $this->visibility->reviewQuery($user, $workflow, $context['scope']);
            $total = (clone $reviewQuery)->count();
            $approvalTypes[] = ['type' => $type, 'module' => $workflow['module'], 'label' => $workflow['label'], 'total' => $total];

            $records = $reviewQuery->with('employee:id,first_name,last_name,employee_number')->orderBy('created_at')->orderBy('id')->limit(10)->get();

            foreach ($records as $record) {
                [$start, $end] = $this->requestWindow($record, $workflow['date_kind']);
                $approvalItems[] = [
                    'id' => $record->id, 'type' => $type, 'module' => $workflow['module'], 'label' => $workflow['label'],
                    'employee' => $this->visibility->employeeLabel($record->employee), 'start' => $start, 'end' => $end,
                    'status' => $record->status, '_submitted' => $record->created_at?->format('Y-m-d H:i:s') ?? '',
                ];
            }
        }

        usort($approvalItems, fn (array $left, array $right): int => [$left['_submitted'], $left['type'], $left['id']] <=> [$right['_submitted'], $right['type'], $right['id']]);
        $approvalItems = array_map(function (array $item): array {
            unset($item['_submitted']);

            return $item;
        }, array_slice($approvalItems, 0, 10));

        return $context + [
            'generated_at' => CarbonImmutable::now(config('app.timezone'))->toAtomString(),
            'workforce' => $this->workforce($user, $context['scope']),
            'attendance' => $this->attendance($user, $context),
            'requests' => $requests,
            'approvals' => ['total' => array_sum(array_column($approvalTypes, 'total')), 'by_type' => $approvalTypes, 'items' => $approvalItems],
        ];
    }

    /** @return array{total:int, active:int, inactive:int}|null */
    private function workforce(User $user, string $scope): ?array
    {
        if (! $user->hasPermission('employees.view') && ! $user->hasPermission('employees.view-team')) {
            return null;
        }

        $groups = $this->visibility->employees($user, $scope)->toBase()->selectRaw('is_active, COUNT(*) AS total')->groupBy('is_active')->get();
        $active = 0;
        $inactive = 0;

        foreach ($groups as $group) {
            if ($group->is_active) {
                $active += (int) $group->total;
            } else {
                $inactive += (int) $group->total;
            }
        }

        return ['total' => $active + $inactive, 'active' => $active, 'inactive' => $inactive];
    }

    /** @return array<string, mixed>|null */
    private function attendance(User $user, array $context): ?array
    {
        if (! $user->hasPermission('attendance.view')) {
            return null;
        }

        $query = $this->visibility->records($user, DailyAttendance::query(), $context['scope'], $user->hasPermission('employees.view'));
        $query = $this->visibility->inRange($query, 'attendance', $context['range']);
        $statuses = $this->statuses($query);
        $totals = (clone $query)->toBase()->selectRaw('COALESCE(SUM(late_minutes), 0) AS late_minutes, COALESCE(SUM(undertime_minutes), 0) AS undertime_minutes, COALESCE(SUM(overtime_minutes), 0) AS overtime_minutes')->first();
        $days = (clone $query)->toBase()->selectRaw('attendance_date AS date, COUNT(*) AS total')->groupBy('attendance_date')->orderBy('attendance_date')->get();

        return [
            'total' => array_sum($statuses), 'by_status' => $statuses,
            'by_day' => $days->map(fn (object $day): array => ['date' => substr($day->date, 0, 10), 'total' => (int) $day->total])->all(),
            'late_minutes' => (int) $totals->late_minutes, 'undertime_minutes' => (int) $totals->undertime_minutes, 'overtime_minutes' => (int) $totals->overtime_minutes,
        ];
    }

    /** @return array<string, int> */
    private function statuses(Builder $query): array
    {
        $groups = (clone $query)->toBase()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->orderBy('status')->get();
        $statuses = [];

        foreach ($groups as $group) {
            $status = $group->status === null || $group->status === '' ? 'unknown' : $group->status;
            $statuses[$status] = ($statuses[$status] ?? 0) + (int) $group->total;
        }

        return $statuses;
    }

    /** @return array{string|null, string|null} */
    private function requestWindow(Model $record, string $kind): array
    {
        return match ($kind) {
            'date' => [$record->start_date?->toDateString(), $record->end_date?->toDateString()],
            'time' => [$record->start_at?->toAtomString(), $record->end_at?->toAtomString()],
            'attendance' => [$record->attendance_date?->toDateString(), $record->attendance_date?->toDateString()],
            default => [$record->created_at?->toAtomString(), null],
        };
    }
}
