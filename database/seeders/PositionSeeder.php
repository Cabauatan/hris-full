<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Position;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PositionSeeder extends Seeder
{
    public function run(): void
    {
        $positionsByDepartment = [
            'STD-HR' => [
                ['code' => 'STD-HR-MANAGER', 'name' => 'HR Manager', 'description' => 'Oversees HR operations and employee administration.'],
                ['code' => 'STD-HR-OFFICER', 'name' => 'HR Officer', 'description' => 'Handles recruitment, employee records, and HR processes.'],
                ['code' => 'STD-HR-ASSISTANT', 'name' => 'HR Assistant', 'description' => 'Supports HR documentation and employee services.'],
            ],
            'STD-ACCOUNTING' => [
                ['code' => 'STD-ACC-MANAGER', 'name' => 'Accounting Manager', 'description' => 'Oversees accounting operations and financial reporting.'],
                ['code' => 'STD-ACC-ACCOUNTANT', 'name' => 'Accountant', 'description' => 'Maintains financial records and prepares accounting reports.'],
                ['code' => 'STD-ACC-ASSISTANT', 'name' => 'Accounting Assistant', 'description' => 'Supports bookkeeping, billing, and payment documentation.'],
            ],
            'STD-IT' => [
                ['code' => 'STD-IT-MANAGER', 'name' => 'IT Manager', 'description' => 'Oversees information systems and technical operations.'],
                ['code' => 'STD-IT-SUPPORT', 'name' => 'IT Support Specialist', 'description' => 'Provides computer, network, and application support.'],
                ['code' => 'STD-IT-DEVELOPER', 'name' => 'Software Developer', 'description' => 'Builds and maintains software applications.'],
            ],
            'STD-ADMIN' => [
                ['code' => 'STD-ADMIN-OFFICER', 'name' => 'Admin Officer', 'description' => 'Coordinates office administration and support services.'],
                ['code' => 'STD-ADMIN-ASSISTANT', 'name' => 'Admin Assistant', 'description' => 'Handles office documentation and administrative tasks.'],
                ['code' => 'STD-ADMIN-RECEPTIONIST', 'name' => 'Receptionist', 'description' => 'Handles front-desk inquiries, visitors, and communications.'],
            ],
            'STD-SALES' => [
                ['code' => 'STD-SALES-MANAGER', 'name' => 'Sales Manager', 'description' => 'Oversees sales activities and customer account development.'],
                ['code' => 'STD-SALES-REPRESENTATIVE', 'name' => 'Sales Representative', 'description' => 'Handles customer accounts and sales opportunities.'],
                ['code' => 'STD-SALES-ASSOCIATE', 'name' => 'Sales Associate', 'description' => 'Assists customers and supports product or service sales.'],
            ],
            'STD-MARKETING' => [
                ['code' => 'STD-MKT-OFFICER', 'name' => 'Marketing Officer', 'description' => 'Coordinates marketing campaigns and brand communication.'],
                ['code' => 'STD-MKT-ASSISTANT', 'name' => 'Marketing Assistant', 'description' => 'Supports campaigns, marketing materials, and promotions.'],
                ['code' => 'STD-MKT-DIGITAL', 'name' => 'Digital Marketing Specialist', 'description' => 'Manages digital campaigns and online marketing channels.'],
            ],
            'STD-CUSTOMER-SERVICE' => [
                ['code' => 'STD-CS-SUPERVISOR', 'name' => 'Customer Service Supervisor', 'description' => 'Supervises customer service operations and escalations.'],
                ['code' => 'STD-CS-REPRESENTATIVE', 'name' => 'Customer Service Representative', 'description' => 'Handles customer inquiries and service requests.'],
                ['code' => 'STD-CS-SUPPORT', 'name' => 'Customer Support Specialist', 'description' => 'Resolves customer issues and provides service support.'],
            ],
            'STD-LOGISTICS' => [
                ['code' => 'STD-LOG-COORDINATOR', 'name' => 'Logistics Coordinator', 'description' => 'Coordinates deliveries, transportation, and logistics records.'],
                ['code' => 'STD-LOG-WAREHOUSE', 'name' => 'Warehouse Staff', 'description' => 'Handles receiving, storage, picking, and inventory movements.'],
                ['code' => 'STD-LOG-DRIVER', 'name' => 'Driver', 'description' => 'Provides delivery, transportation, and vehicle support.'],
            ],
        ];

        DB::transaction(function () use ($positionsByDepartment): void {
            $this->call(DepartmentSeeder::class);

            foreach ($positionsByDepartment as $departmentCode => $positions) {
                $department = Department::query()->with('division')->where('code', $departmentCode)->firstOrFail();
                $isActive = $department->is_active && ($department->division?->is_active ?? true);

                foreach ($positions as $position) {
                    Position::firstOrCreate(
                        ['code' => $position['code']],
                        $position + [
                            'department_id' => $department->id,
                            'is_active' => $isActive,
                        ]
                    );
                }
            }
        });
    }
}
