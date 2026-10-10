<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class WorkScheduleDayResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'work_schedule_id',
        'day_of_week',
        'is_working_day',
        'shift_id',
        'created_at',
        'updated_at',
    ];

    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'work_schedule' => $this->whenLoaded('workSchedule', fn (): array => [
                'id' => $this->workSchedule->id,
                'code' => $this->workSchedule->code,
                'name' => $this->workSchedule->name,
            ]),
            'shift' => $this->whenLoaded('shift', fn (): array => [
                'id' => $this->shift->id,
                'code' => $this->shift->code,
                'name' => $this->shift->name,
                'start_time' => $this->shift->start_time,
                'end_time' => $this->shift->end_time,
                'is_overnight' => $this->shift->is_overnight,
            ]),
        ];
    }
}
