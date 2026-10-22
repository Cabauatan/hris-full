<?php

namespace App\Services\Workspace;

use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class BirthdayScope
{
    public function __construct(private readonly WorkspaceReadScope $visibility) {}

    /** @return Builder<Employee> */
    public function coworkers(Employee $employee): Builder
    {
        $query = Employee::query()->where('is_active', true)->whereKeyNot($employee->getKey());

        if (! $employee->exists || ! $employee->is_active) {
            return $query->whereRaw('1 = 0');
        }

        $employee->loadMissing('position.department.division');
        $position = $employee->position;
        $department = $position?->department;

        if ($position === null || ! $position->is_active || $department === null || ! $department->is_active) {
            return $query->whereRaw('1 = 0');
        }

        $divisionId = $department->division?->is_active ? $department->division->getKey() : null;

        return $query->whereHas('position', fn (Builder $positions): Builder => $positions
            ->where('is_active', true)
            ->whereHas('department', fn (Builder $departments): Builder => $departments
                ->where('is_active', true)
                ->where(function (Builder $departments) use ($department, $divisionId): void {
                    $departments->whereKey($department->getKey());

                    if ($divisionId !== null) {
                        $departments->orWhereHas('division', fn (Builder $divisions): Builder => $divisions
                            ->whereKey($divisionId)->where('is_active', true));
                    }
                })));
    }

    /** @return Builder<Employee> */
    public function calendarEmployees(User $user, string $scope): Builder
    {
        $visible = $this->visibility->employees($user, $scope);

        if ($scope !== 'visible' || ! $user->is_active) {
            return $visible;
        }

        $employee = $user->employee()->first();

        if ($employee === null || ! $employee->is_active) {
            return $visible;
        }

        return Employee::query()->where(fn (Builder $employees): Builder => $employees
            ->whereIn('employees.id', $visible->select('employees.id'))
            ->orWhereIn('employees.id', $this->coworkers($employee)->select('employees.id')));
    }

    public function matchesDate(Employee $employee, CarbonImmutable $date): bool
    {
        $birthDate = $employee->birth_date;

        if ($birthDate === null || $date->year <= $birthDate->year || $date->month !== $birthDate->month) {
            return false;
        }

        return $date->day === min($birthDate->day, $date->daysInMonth);
    }

    /** @return array{title: string, message: string, path: string} */
    public function message(Employee $employee, CarbonImmutable $date): array
    {
        $name = trim($employee->first_name.' '.$employee->last_name);
        $age = $date->year - $employee->birth_date->year;

        return [
            'title' => 'Birthday · '.$name,
            'message' => $name.' celebrates their birthday on '.$date->format('F j, Y').' (turning '.$age.').',
            'path' => '/calendar?scope=visible&types=birthday',
        ];
    }
}
