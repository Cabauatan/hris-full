<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class ApiController extends Controller
{
    protected function paginate(Request $request, Builder $query): LengthAwarePaginator
    {
        $filters = array_intersect_key([
            'employee_id' => ['sometimes', 'integer', 'numeric', 'min:1'],
            'leave_type_id' => ['sometimes', 'integer', 'numeric', 'min:1'],
            'payroll_run_id' => ['sometimes', 'integer', 'numeric', 'min:1'],
            'payroll_period_id' => ['sometimes', 'integer', 'numeric', 'min:1'],
            'status' => ['sometimes', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
            'attendance_date' => ['sometimes', 'date_format:Y-m-d'],
        ], array_flip($query->getModel()->getFillable()));

        $input = $request->validate($filters + [
            'per_page' => ['sometimes', 'integer', 'numeric', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'numeric', 'min:1'],
        ]);

        foreach (array_keys($filters) as $field) {
            if (array_key_exists($field, $input)) {
                $query->where($query->getModel()->qualifyColumn($field), $input[$field]);
            }
        }

        return $query->orderByDesc($query->getModel()->qualifyColumn('id'))
            ->paginate($input['per_page'] ?? 15)->withQueryString();
    }

    protected function currentEmployee(Request $request): Employee
    {
        $employee = $request->user()->employee;

        abort_unless($employee instanceof Employee, 403, 'An employee profile is required.');

        return $employee;
    }

    protected function visibleEmployeeRecords(Request $request, Builder $query, bool $all, bool $includeTeam = true, ?Builder $delegatedEmployees = null): Builder
    {
        if ($all) {
            return $query;
        }

        $employeeId = $request->user()->employee()->value('id');

        return $query->whereIn('employee_id', Employee::query()
            ->select('id')
            ->where(function (Builder $employees) use ($request, $employeeId, $includeTeam, $delegatedEmployees): void {
                $employees->where('id', $employeeId ?? 0);

                if ($employeeId !== null && $includeTeam && $request->user()->hasPermission('employees.view-team')) {
                    $employees->orWhere('supervisor_employee_id', $employeeId);
                }

                if ($delegatedEmployees !== null) {
                    $employees->orWhereIn('id', $delegatedEmployees->select('employees.id'));
                }
            }));
    }
}
