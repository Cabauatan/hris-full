<?php

namespace Database\Seeders;

use App\Models\Holiday;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PhilippineHolidaySeeder extends Seeder
{
    public function run(): void
    {
        /** @var list<array{proclamation: string, source: string, holidays: list<array{0: string, 1: string, 2: string}>}> $declarations */
        $declarations = [
            [
                'proclamation' => 'Proclamation No. 1006, s. 2025',
                'source' => 'https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/7/99678',
                'holidays' => [
                    ['2026-01-01', "New Year's Day", 'regular'],
                    ['2026-02-17', 'Chinese New Year', 'special_non_working'],
                    ['2026-02-25', 'EDSA People Power Revolution Anniversary', 'special_working'],
                    ['2026-04-02', 'Maundy Thursday', 'regular'],
                    ['2026-04-03', 'Good Friday', 'regular'],
                    ['2026-04-04', 'Black Saturday', 'special_non_working'],
                    ['2026-04-09', 'Araw ng Kagitingan', 'regular'],
                    ['2026-05-01', 'Labor Day', 'regular'],
                    ['2026-06-12', 'Independence Day', 'regular'],
                    ['2026-08-21', 'Ninoy Aquino Day', 'special_non_working'],
                    ['2026-08-31', 'National Heroes Day', 'regular'],
                    ['2026-11-01', "All Saints' Day", 'special_non_working'],
                    ['2026-11-02', "All Souls' Day", 'special_non_working'],
                    ['2026-11-30', 'Bonifacio Day', 'regular'],
                    ['2026-12-08', 'Feast of the Immaculate Conception of Mary', 'special_non_working'],
                    ['2026-12-24', 'Christmas Eve', 'special_non_working'],
                    ['2026-12-25', 'Christmas Day', 'regular'],
                    ['2026-12-30', 'Rizal Day', 'regular'],
                    ['2026-12-31', 'Last Day of the Year', 'special_non_working'],
                ],
            ],
            [
                'proclamation' => 'Proclamation No. 1189, s. 2026',
                'source' => 'https://pco.gov.ph/news_releases/proclamation-no-1189-s-2026-declaring-friday-20-march-2026-a-regular-holiday-throughout-the-country-in-observance-of-eidl-fitr-feast-of-ramadhan/',
                'holidays' => [
                    ['2026-03-20', "Eid'l Fitr", 'regular'],
                ],
            ],
            [
                'proclamation' => 'Proclamation No. 1264, s. 2026',
                'source' => 'https://pco.gov.ph/news_releases/proclamation-no-1264-s-2026/',
                'holidays' => [
                    ['2026-05-27', "Eid'l Adha", 'regular'],
                ],
            ],
            [
                'proclamation' => 'Proclamation No. 1427, s. 2026',
                'source' => 'https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/7/102791',
                'holidays' => [
                    ['2027-01-01', "New Year's Day", 'regular'],
                    ['2027-02-06', 'Chinese New Year', 'special_non_working'],
                    ['2027-02-25', 'EDSA People Power Revolution Anniversary', 'special_working'],
                    ['2027-03-25', 'Maundy Thursday', 'regular'],
                    ['2027-03-26', 'Good Friday', 'regular'],
                    ['2027-03-27', 'Black Saturday', 'special_non_working'],
                    ['2027-04-09', 'Araw ng Kagitingan', 'regular'],
                    ['2027-05-01', 'Labor Day', 'regular'],
                    ['2027-06-12', 'Independence Day', 'regular'],
                    ['2027-08-21', 'Ninoy Aquino Day', 'special_non_working'],
                    ['2027-08-30', 'National Heroes Day', 'regular'],
                    ['2027-11-01', "All Saints' Day", 'special_non_working'],
                    ['2027-11-02', "All Souls' Day", 'special_non_working'],
                    ['2027-11-30', 'Bonifacio Day', 'regular'],
                    ['2027-12-08', 'Feast of the Immaculate Conception of Mary', 'special_non_working'],
                    ['2027-12-24', 'Christmas Eve', 'special_non_working'],
                    ['2027-12-25', 'Christmas Day', 'regular'],
                    ['2027-12-30', 'Rizal Day', 'regular'],
                    ['2027-12-31', 'Last Day of the Year', 'special_non_working'],
                ],
            ],
        ];

        DB::transaction(function () use ($declarations): void {
            foreach ($declarations as $declaration) {
                foreach ($declaration['holidays'] as [$date, $name, $type]) {
                    $payDescription = match ($type) {
                        'regular' => 'Holiday pay for unworked time is subject to employee eligibility.',
                        'special_non_working' => 'Pay for unworked time follows company policy or CBA.',
                        'special_working' => 'Ordinary working-day pay applies.',
                    };

                    Holiday::query()->whereDate('holiday_date', $date)->firstOrCreate(
                        ['name' => $name],
                        [
                            'holiday_date' => $date,
                            'holiday_type' => $type,
                            'description' => "Nationwide Philippine holiday under {$declaration['proclamation']}. {$payDescription} Source: {$declaration['source']}",
                            'is_working_day' => $type === 'special_working',
                            'is_paid' => $type === 'regular',
                            'is_active' => true,
                            'created_by_user_id' => null,
                        ]
                    );
                }
            }
        });
    }
}
