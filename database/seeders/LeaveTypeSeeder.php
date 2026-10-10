<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Seeder;

class LeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        $leaveTypes = [
            [
                'code' => 'VL',
                'name' => 'Vacation Leave',
                'description' => 'Vacation or planned personal leave.',
                'is_paid' => true,
                'requires_balance' => true,
                'allows_partial_day' => true,
                'requires_document' => false,
                'is_active' => true,
            ],
            [
                'code' => 'SL',
                'name' => 'Sick Leave',
                'description' => 'Leave due to illness or medical reasons.',
                'is_paid' => true,
                'requires_balance' => true,
                'allows_partial_day' => true,
                'requires_document' => false,
                'is_active' => true,
            ],
            [
                'code' => 'EL',
                'name' => 'Emergency Leave',
                'description' => 'Leave for urgent or emergency personal circumstances.',
                'is_paid' => true,
                'requires_balance' => true,
                'allows_partial_day' => true,
                'requires_document' => false,
                'is_active' => true,
            ],
            [
                'code' => 'LWOP',
                'name' => 'Leave Without Pay',
                'description' => 'Approved unpaid leave.',
                'is_paid' => false,
                'requires_balance' => false,
                'allows_partial_day' => true,
                'requires_document' => false,
                'is_active' => true,
            ],
        ];

        foreach ($leaveTypes as $leaveType) {
            LeaveType::updateOrCreate(
                ['code' => $leaveType['code']],
                $leaveType
            );
        }
    }
}
