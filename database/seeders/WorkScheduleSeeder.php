<?php

namespace Database\Seeders;

use App\Models\Shift;
use App\Models\WorkSchedule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WorkScheduleSeeder extends Seeder
{
    public function run(): void
    {
        $schedules = [
            [
                'code' => 'STD-MF-OFFICE-0800',
                'name' => 'Monday-Friday / Office 08:00',
                'description' => 'Monday to Friday office schedule; Saturday and Sunday are rest days.',
                'shift_code' => 'STD-OFFICE-0800',
                'working_days' => [1, 2, 3, 4, 5],
            ],
            [
                'code' => 'STD-MF-OFFICE-0900',
                'name' => 'Monday-Friday / Office 09:00',
                'description' => 'Monday to Friday office schedule; Saturday and Sunday are rest days.',
                'shift_code' => 'STD-OFFICE-0900',
                'working_days' => [1, 2, 3, 4, 5],
            ],
            [
                'code' => 'STD-MS-OFFICE-0800',
                'name' => 'Monday-Saturday / Office 08:00',
                'description' => 'Monday to Saturday office schedule; Sunday is a rest day.',
                'shift_code' => 'STD-OFFICE-0800',
                'working_days' => [1, 2, 3, 4, 5, 6],
            ],
            [
                'code' => 'STD-MS-MORNING-0600',
                'name' => 'Monday-Saturday / Morning 06:00',
                'description' => 'Monday to Saturday morning schedule; Sunday is a rest day.',
                'shift_code' => 'STD-MORNING-0600',
                'working_days' => [1, 2, 3, 4, 5, 6],
            ],
            [
                'code' => 'STD-MF-AFTERNOON-1400',
                'name' => 'Monday-Friday / Afternoon 14:00',
                'description' => 'Monday to Friday afternoon schedule; Saturday and Sunday are rest days.',
                'shift_code' => 'STD-AFTERNOON-1400',
                'working_days' => [1, 2, 3, 4, 5],
            ],
            [
                'code' => 'STD-MF-NIGHT-2200',
                'name' => 'Monday-Friday / Night 22:00',
                'description' => 'Shifts start Monday to Friday and finish the next morning; Saturday and Sunday have no scheduled shift starts.',
                'shift_code' => 'STD-NIGHT-2200',
                'working_days' => [1, 2, 3, 4, 5],
            ],
        ];

        DB::transaction(function () use ($schedules): void {
            $this->call(ShiftSeeder::class);

            foreach ($schedules as $schedule) {
                $shift = Shift::query()->where('code', $schedule['shift_code'])->firstOrFail();
                $workSchedule = WorkSchedule::firstOrCreate(
                    ['code' => $schedule['code']],
                    [
                        'name' => $schedule['name'],
                        'description' => $schedule['description'],
                        'is_active' => $shift->is_active,
                    ]
                );

                if (! $workSchedule->wasRecentlyCreated) {
                    continue;
                }

                foreach (range(1, 7) as $dayOfWeek) {
                    $isWorkingDay = in_array($dayOfWeek, $schedule['working_days'], true);

                    $workSchedule->days()->create([
                        'day_of_week' => $dayOfWeek,
                        'is_working_day' => $isWorkingDay,
                        'shift_id' => $isWorkingDay ? $shift->id : null,
                    ]);
                }
            }
        });
    }
}
