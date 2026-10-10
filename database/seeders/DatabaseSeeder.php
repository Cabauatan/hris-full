<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            RecruitmentPermissionSeeder::class,
            AdminUserSeeder::class,
            LeaveTypeSeeder::class,
            CompanyProfileSeeder::class,
        ]);
    }
}
