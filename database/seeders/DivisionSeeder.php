<?php

namespace Database\Seeders;

use App\Models\Division;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DivisionSeeder extends Seeder
{
    public function run(): void
    {
        $divisions = [
            [
                'code' => 'STD-CORPORATE',
                'name' => 'Corporate Services',
                'description' => 'Human resources, finance, technology, and administrative support.',
            ],
            [
                'code' => 'STD-OPERATIONS',
                'name' => 'Operations',
                'description' => 'Customer service, logistics, and day-to-day operational support.',
            ],
            [
                'code' => 'STD-COMMERCIAL',
                'name' => 'Sales and Marketing',
                'description' => 'Sales, customer acquisition, marketing, and business growth.',
            ],
        ];

        DB::transaction(function () use ($divisions): void {
            foreach ($divisions as $division) {
                Division::firstOrCreate(
                    ['code' => $division['code']],
                    $division + ['is_active' => true]
                );
            }
        });
    }
}
