<?php

namespace Database\Seeders;

use App\Models\Shift;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShiftSeeder extends Seeder
{
    public function run(): void
    {
        $shifts = [
            [
                'code' => 'STD-OFFICE-0800',
                'name' => 'Office / Day (08:00-17:00)',
                'start_time' => '08:00:00',
                'end_time' => '17:00:00',
                'is_overnight' => false,
            ],
            [
                'code' => 'STD-OFFICE-0900',
                'name' => 'Office / Day (09:00-18:00)',
                'start_time' => '09:00:00',
                'end_time' => '18:00:00',
                'is_overnight' => false,
            ],
            [
                'code' => 'STD-MORNING-0600',
                'name' => 'Morning (06:00-15:00)',
                'start_time' => '06:00:00',
                'end_time' => '15:00:00',
                'is_overnight' => false,
            ],
            [
                'code' => 'STD-AFTERNOON-1400',
                'name' => 'Afternoon (14:00-23:00)',
                'start_time' => '14:00:00',
                'end_time' => '23:00:00',
                'is_overnight' => false,
            ],
            [
                'code' => 'STD-NIGHT-2200',
                'name' => 'Night (22:00-07:00 next day)',
                'start_time' => '22:00:00',
                'end_time' => '07:00:00',
                'is_overnight' => true,
            ],
        ];

        DB::transaction(function () use ($shifts): void {
            foreach ($shifts as $shift) {
                Shift::firstOrCreate(
                    ['code' => $shift['code']],
                    $shift + [
                        'break_minutes' => 60,
                        'late_grace_minutes' => 0,
                        'undertime_grace_minutes' => 0,
                        'is_active' => true,
                        'remarks' => null,
                    ]
                );
            }
        });
    }
}
