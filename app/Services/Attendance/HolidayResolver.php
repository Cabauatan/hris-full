<?php

namespace App\Services\Attendance;

use App\Models\Holiday;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class HolidayResolver
{
    public function resolve(
        CarbonInterface|string $date
    ): Collection {
        $targetDate = Carbon::parse($date)->toDateString();

        return Holiday::query()
            ->whereDate('holiday_date', $targetDate)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    public function isHoliday(
        CarbonInterface|string $date
    ): bool {
        return $this->resolve($date)->isNotEmpty();
    }

    public function isNonWorkingHoliday(
        CarbonInterface|string $date
    ): bool {
        return $this->resolve($date)
            ->contains(
                fn (Holiday $holiday) => ! $holiday->is_working_day
            );
    }
}
