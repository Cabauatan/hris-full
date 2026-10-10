<?php

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AuthenticatedUserResource extends ApiResource
{
    protected const FIELDS = ['id', 'uuid', 'name', 'email', 'is_active', 'last_login_at'];

    public function toArray(Request $request): array
    {
        $employee = $this->resource->employee;
        $permissions = $this->resource->permissions->pluck('code')
            ->merge($this->resource->roles->flatMap(fn (Role $role): Collection => $role->permissions->pluck('code')))
            ->unique()->sort()->values()->all();

        return parent::toArray($request) + [
            'roles' => $this->resource->roles->pluck('code')->unique()->sort()->values()->all(),
            'permissions' => $permissions,
            'employee' => $employee === null ? null : [
                'id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
            ],
        ];
    }
}
