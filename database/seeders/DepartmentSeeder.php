<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Division;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            [
                'code' => 'STD-HR',
                'name' => 'Human Resources',
                'description' => 'Recruitment, employee records, benefits, and HR administration.',
                'division_code' => 'STD-CORPORATE',
            ],
            [
                'code' => 'STD-ACCOUNTING',
                'name' => 'Accounting',
                'description' => 'Accounting records, financial reporting, and payment processing.',
                'division_code' => 'STD-CORPORATE',
            ],
            [
                'code' => 'STD-IT',
                'name' => 'Information Technology',
                'description' => 'Systems, software, networks, and technical support.',
                'division_code' => 'STD-CORPORATE',
            ],
            [
                'code' => 'STD-ADMIN',
                'name' => 'Administration',
                'description' => 'Office administration, reception, and general support services.',
                'division_code' => 'STD-CORPORATE',
            ],
            [
                'code' => 'STD-CUSTOMER-SERVICE',
                'name' => 'Customer Service',
                'description' => 'Customer inquiries, support, and service coordination.',
                'division_code' => 'STD-OPERATIONS',
            ],
            [
                'code' => 'STD-LOGISTICS',
                'name' => 'Logistics',
                'description' => 'Deliveries, transportation, inventory handling, and warehouse support.',
                'division_code' => 'STD-OPERATIONS',
            ],
            [
                'code' => 'STD-SALES',
                'name' => 'Sales',
                'description' => 'Sales activities, customer accounts, and product or service sales.',
                'division_code' => 'STD-COMMERCIAL',
            ],
            [
                'code' => 'STD-MARKETING',
                'name' => 'Marketing',
                'description' => 'Marketing campaigns, brand communication, and digital promotion.',
                'division_code' => 'STD-COMMERCIAL',
            ],
        ];

        DB::transaction(function () use ($departments): void {
            $this->call(DivisionSeeder::class);

            foreach ($departments as $department) {
                $division = Division::query()->where('code', $department['division_code'])->firstOrFail();

                Department::firstOrCreate(
                    ['code' => $department['code']],
                    [
                        'division_id' => $division->id,
                        'name' => $department['name'],
                        'description' => $department['description'],
                        'is_active' => $division->is_active,
                    ]
                );
            }
        });
    }
}
