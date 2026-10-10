<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['code' => 'super_admin', 'name' => 'Super Administrator'],
            ['code' => 'hr_admin', 'name' => 'HR Administrator'],
            ['code' => 'hr_staff', 'name' => 'HR Staff'],
            ['code' => 'payroll_admin', 'name' => 'Payroll Administrator'],
            ['code' => 'manager', 'name' => 'Manager'],
            ['code' => 'supervisor', 'name' => 'Supervisor'],
            ['code' => 'employee', 'name' => 'Employee'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['code' => $role['code']],
                [
                    'name' => $role['name'],
                    'description' => null,
                    'is_active' => true,
                ]
            );
        }

        $permissions = [
            // Recruitment
            'recruitment.view',
            'recruitment.manage',
            'recruitment.grade',

            // Organization
            'organization.view',
            'organization.manage',

            // Employees
            'employees.view',
            'employees.view-team',
            'employees.create',
            'employees.update',
            'employees.manage-documents',
            'employees.manage-compensation',
            'employees.manage-employment',

            // Scheduling
            'schedules.view',
            'schedules.manage',
            'schedules.assign',

            // Attendance
            'attendance.view',
            'attendance.manage',
            'attendance.import',

            // Attendance Corrections
            'attendance-corrections.view',
            'attendance-corrections.create',
            'attendance-corrections.approve',

            // Official Business
            'official-business.view',
            'official-business.create',
            'official-business.approve',

            // Travel Orders
            'travel-orders.view',
            'travel-orders.create',
            'travel-orders.approve',

            // Overtime
            'overtime.view',
            'overtime.create',
            'overtime.approve',

            // Leave
            'leave.view',
            'leave.create',
            'leave.approve',
            'leave.manage-types',
            'leave.manage-credits',

            // CTO
            'cto.view',
            'cto.create',
            'cto.approve',
            'cto.manage-credits',

            // Payroll
            'payroll.view',
            'payroll.process',
            'payroll.finalize',

            // Payslips
            'payslips.view',
            'payslips.generate',
            'payslips.publish',

            // Profile Change Requests
            'profile-changes.view',
            'profile-changes.create',
            'profile-changes.approve',

            // Announcements
            'announcements.view',
            'announcements.manage',

            // Holidays
            'holidays.view',
            'holidays.manage',

            // System Settings
            'settings.view',
            'settings.manage',

            // Audit Trail
            'audit-logs.view',

            // Approval Delegation
            'approval-delegations.view',
            'approval-delegations.manage',
        ];

        foreach ($permissions as $code) {
            Permission::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $this->permissionName($code),
                    'description' => null,
                    'is_active' => true,
                ]
            );
        }

        $superAdmin = Role::where('code', 'super_admin')->firstOrFail();

        $superAdmin->permissions()->sync(
            Permission::query()->pluck('id')->all()
        );
        $rolePermissions = [
            'hr_admin' => [
                'recruitment.view',
                'recruitment.manage',
                'recruitment.grade',

                'organization.view',
                'organization.manage',

                'employees.view',
                'employees.create',
                'employees.update',
                'employees.manage-documents',
                'employees.manage-compensation',
                'employees.manage-employment',

                'schedules.view',
                'schedules.manage',
                'schedules.assign',

                'attendance.view',
                'attendance.manage',
                'attendance.import',

                'attendance-corrections.view',
                'attendance-corrections.create',
                'attendance-corrections.approve',

                'official-business.view',
                'official-business.create',
                'official-business.approve',

                'travel-orders.view',
                'travel-orders.create',
                'travel-orders.approve',

                'overtime.view',
                'overtime.create',
                'overtime.approve',

                'leave.view',
                'leave.create',
                'leave.approve',
                'leave.manage-types',
                'leave.manage-credits',

                'cto.view',
                'cto.create',
                'cto.approve',
                'cto.manage-credits',

                'profile-changes.view',
                'profile-changes.create',
                'profile-changes.approve',

                'announcements.view',
                'announcements.manage',

                'holidays.view',
                'holidays.manage',

                'approval-delegations.view',
                'approval-delegations.manage',

                'audit-logs.view',
            ],

            'hr_staff' => [
                'recruitment.view',
                'recruitment.manage',
                'recruitment.grade',

                'organization.view',

                'employees.view',
                'employees.create',
                'employees.update',
                'employees.manage-documents',
                'employees.manage-employment',

                'schedules.view',
                'schedules.manage',
                'schedules.assign',

                'attendance.view',
                'attendance.manage',
                'attendance.import',

                'attendance-corrections.view',
                'attendance-corrections.create',

                'official-business.view',
                'official-business.create',

                'travel-orders.view',
                'travel-orders.create',

                'overtime.view',
                'overtime.create',

                'leave.view',
                'leave.create',

                'cto.view',
                'cto.create',

                'profile-changes.view',
                'profile-changes.create',

                'announcements.view',
                'holidays.view',
            ],

            'payroll_admin' => [
                'employees.view',
                'employees.manage-compensation',

                'attendance.view',
                'overtime.view',
                'leave.view',
                'cto.view',

                'payroll.view',
                'payroll.process',
                'payroll.finalize',

                'payslips.view',
                'payslips.generate',
                'payslips.publish',

                'holidays.view',
            ],

            'manager' => [
                'organization.view',
                'employees.view-team',
                'schedules.view',
                'attendance.view',

                'attendance-corrections.view',
                'attendance-corrections.create',
                'attendance-corrections.approve',

                'official-business.view',
                'official-business.create',
                'official-business.approve',

                'travel-orders.view',
                'travel-orders.create',
                'travel-orders.approve',

                'overtime.view',
                'overtime.create',
                'overtime.approve',

                'leave.view',
                'leave.create',
                'leave.approve',

                'cto.view',
                'cto.create',
                'cto.approve',

                'profile-changes.view',
                'profile-changes.create',

                'announcements.view',
                'holidays.view',

                'approval-delegations.view',
                'approval-delegations.manage',
            ],

            'supervisor' => [
                'organization.view',
                'employees.view-team',
                'schedules.view',
                'attendance.view',

                'attendance-corrections.view',
                'attendance-corrections.create',
                'attendance-corrections.approve',

                'official-business.view',
                'official-business.create',
                'official-business.approve',

                'travel-orders.view',
                'travel-orders.create',
                'travel-orders.approve',

                'overtime.view',
                'overtime.create',
                'overtime.approve',

                'leave.view',
                'leave.create',
                'leave.approve',

                'cto.view',
                'cto.create',
                'cto.approve',

                'profile-changes.view',
                'profile-changes.create',

                'announcements.view',
                'holidays.view',

                'approval-delegations.view',
                'approval-delegations.manage',
            ],

            'employee' => [
                'schedules.view',
                'attendance.view',

                'attendance-corrections.view',
                'attendance-corrections.create',

                'official-business.view',
                'official-business.create',

                'travel-orders.view',
                'travel-orders.create',

                'overtime.view',
                'overtime.create',

                'leave.view',
                'leave.create',

                'cto.view',
                'cto.create',

                'payslips.view',

                'profile-changes.view',
                'profile-changes.create',

                'announcements.view',
                'holidays.view',
            ],
        ];

        foreach ($rolePermissions as $roleCode => $permissionCodes) {
            $role = Role::where('code', $roleCode)->firstOrFail();

            $permissions = Permission::query()
                ->whereIn('code', $permissionCodes)
                ->get();

            $foundCodes = $permissions
                ->pluck('code')
                ->all();

            $missingCodes = array_diff(
                $permissionCodes,
                $foundCodes
            );

            if ($missingCodes !== []) {
                throw new \RuntimeException(
                    "Missing permissions for role [{$roleCode}]: "
                    .implode(', ', $missingCodes)
                );
            }

            $role->permissions()->sync(
                $permissions->pluck('id')->all()
            );
        }
    }

    private function permissionName(string $code): string
    {
        return str($code)
            ->replace(['.', '-'], ' ')
            ->title()
            ->toString();
    }
}
