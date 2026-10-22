<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Services\Attendance\HolidayResolver;
use Database\Seeders\PhilippineHolidaySeeder;
use Illuminate\Database\Eloquent\Model;
use Tests\FormRequestTestCase;

class PhilippineHolidaySeederTest extends FormRequestTestCase
{
    public function test_seeding_creates_only_confirmed_2026_and_2027_nationwide_holidays_with_correct_classifications(): void
    {
        $this->freezeTime();

        $this->seed(PhilippineHolidaySeeder::class);

        $this->assertDatabaseCount('holidays', 40);
        foreach ([2026 => [12, 8, 1], 2027 => [10, 8, 1]] as $year => [$regular, $nonWorking, $working]) {
            $this->assertSame($regular, Holiday::query()->whereYear('holiday_date', $year)->where('holiday_type', 'regular')->count());
            $this->assertSame($nonWorking, Holiday::query()->whereYear('holiday_date', $year)->where('holiday_type', 'special_non_working')->count());
            $this->assertSame($working, Holiday::query()->whereYear('holiday_date', $year)->where('holiday_type', 'special_working')->count());
        }
        foreach ([
            'regular' => [22, false, true],
            'special_non_working' => [16, false, false],
            'special_working' => [2, true, false],
        ] as $type => [$count, $isWorking, $isPaid]) {
            $this->assertSame($count, Holiday::query()->where('holiday_type', $type)
                ->where('is_working_day', $isWorking)->where('is_paid', $isPaid)->count());
        }
        $this->assertSame(40, Holiday::query()->where('is_active', true)->whereNull('created_by_user_id')->count());
        $this->assertSame(0, Holiday::query()->whereYear('holiday_date', 2027)->where('name', 'like', '%Eid%')->count());

        $confirmedDates = [
            '2026-02-17' => ['Chinese New Year', 'special_non_working'],
            '2026-03-20' => ["Eid'l Fitr", 'regular'],
            '2026-04-02' => ['Maundy Thursday', 'regular'],
            '2026-04-03' => ['Good Friday', 'regular'],
            '2026-04-04' => ['Black Saturday', 'special_non_working'],
            '2026-05-27' => ["Eid'l Adha", 'regular'],
            '2026-08-31' => ['National Heroes Day', 'regular'],
            '2027-02-06' => ['Chinese New Year', 'special_non_working'],
            '2027-03-25' => ['Maundy Thursday', 'regular'],
            '2027-03-26' => ['Good Friday', 'regular'],
            '2027-03-27' => ['Black Saturday', 'special_non_working'],
            '2027-08-30' => ['National Heroes Day', 'regular'],
        ];
        foreach ($confirmedDates as $date => [$name, $type]) {
            $holiday = Holiday::query()->whereDate('holiday_date', $date)->firstOrFail();
            $this->assertSame($name, $holiday->name);
            $this->assertSame($type, $holiday->holiday_type);
        }
        foreach ([
            'https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/7/99678' => 19,
            'https://pco.gov.ph/news_releases/proclamation-no-1189-s-2026-declaring-friday-20-march-2026-a-regular-holiday-throughout-the-country-in-observance-of-eidl-fitr-feast-of-ramadhan/' => 1,
            'https://pco.gov.ph/news_releases/proclamation-no-1264-s-2026/' => 1,
            'https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/7/102791' => 19,
        ] as $source => $count) {
            $this->assertSame($count, Holiday::query()->where('description', 'like', '%'.$source.'%')->count());
        }
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('payroll_entries', 0);
    }

    public function test_repeated_seeding_preserves_existing_hr_customizations_local_holidays_employees_and_timestamps(): void
    {
        $this->travelTo('2026-10-05 08:00:00');
        $actor = $this->user();
        $employee = $this->employee($actor);
        $employeeBefore = $employee->refresh()->getAttributes();
        $existingHoliday = Holiday::query()->create([
            'holiday_date' => '2026-01-01',
            'name' => "New Year's Day",
            'holiday_type' => 'company',
            'description' => 'HR retains its existing classification and pay policy.',
            'is_working_day' => true,
            'is_paid' => false,
            'is_active' => false,
            'created_by_user_id' => $actor->id,
        ]);
        $existingBefore = $existingHoliday->refresh()->getAttributes();
        $localHoliday = Holiday::query()->create([
            'holiday_date' => '2026-01-01',
            'name' => 'Local company observance',
            'holiday_type' => 'company',
            'description' => 'Retain a differently named holiday on the same date.',
            'is_working_day' => true,
            'is_paid' => true,
            'is_active' => false,
            'created_by_user_id' => $actor->id,
        ]);
        $localBefore = $localHoliday->refresh()->getAttributes();
        $this->travelTo('2026-10-06 08:00:00');

        $this->seed(PhilippineHolidaySeeder::class);

        $this->assertDatabaseCount('holidays', 41);
        $this->assertSame($existingBefore, $existingHoliday->refresh()->getAttributes());
        $this->assertSame($localBefore, $localHoliday->refresh()->getAttributes());
        $before = $this->holidayRecords();
        $this->travelTo('2026-10-07 08:00:00');

        $this->seed(PhilippineHolidaySeeder::class);

        $this->assertSame($before, $this->holidayRecords());
        $this->assertSame($employeeBefore, $employee->refresh()->getAttributes());
        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertFalse($this->app->make(HolidayResolver::class)->isHoliday('2026-01-01'));
    }

    public function test_seeded_holidays_resolve_regular_and_special_non_working_days_without_treating_edsa_as_non_working(): void
    {
        $this->freezeTime();
        $this->seed(PhilippineHolidaySeeder::class);
        $resolver = $this->app->make(HolidayResolver::class);

        foreach ([
            '2026-01-01' => [true, true],
            '2026-02-17' => [true, true],
            '2026-02-25' => [true, false],
            '2026-01-02' => [false, false],
        ] as $date => [$isHoliday, $isNonWorking]) {
            $this->assertSame($isHoliday, $resolver->isHoliday($date), $date);
            $this->assertSame($isNonWorking, $resolver->isNonWorkingHoliday($date), $date);
        }
        $edsa = $resolver->resolve('2027-02-25')->sole();
        $this->assertSame('special_working', $edsa->holiday_type);
        $this->assertTrue($edsa->is_working_day);
        $this->assertFalse($edsa->is_paid);
    }

    /** @return list<array<string, mixed>> */
    private function holidayRecords(): array
    {
        return Holiday::query()->orderBy('id')->get()->map(
            static fn (Model $holiday): array => $holiday->getAttributes(),
        )->all();
    }
}
