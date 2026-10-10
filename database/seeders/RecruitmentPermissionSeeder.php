<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RecruitmentPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $permissionIds = [];

            foreach (['recruitment.view', 'recruitment.manage', 'recruitment.grade'] as $code) {
                $permission = Permission::query()->updateOrCreate(
                    ['code' => $code],
                    [
                        'name' => str($code)->replace('.', ' ')->title()->toString(),
                        'description' => null,
                        'is_active' => true,
                    ],
                );
                $permissionIds[] = $permission->id;
            }

            $roles = Role::query()
                ->whereIn('code', ['super_admin', 'hr_admin', 'hr_staff'])
                ->where('is_active', true)
                ->get();

            foreach ($roles as $role) {
                $role->permissions()->syncWithoutDetaching($permissionIds);
            }
        });
    }
}
