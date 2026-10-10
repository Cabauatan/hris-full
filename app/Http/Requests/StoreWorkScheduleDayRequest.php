<?php

namespace App\Http\Requests;

use App\Models\Shift;
use App\Models\WorkScheduleDay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreWorkScheduleDayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', WorkScheduleDay::class) === true;
    }

    public function rules(): array
    {
        return [
            'work_schedule_id' => ['required', 'integer', 'numeric', 'exists:work_schedules,id'],
            'day_of_week' => ['required', 'integer', 'numeric', 'between:1,7', Rule::unique('work_schedule_days', 'day_of_week')->where('work_schedule_id', is_numeric($this->input('work_schedule_id')) ? (int) $this->input('work_schedule_id') : null)],
            'is_working_day' => ['sometimes', 'boolean'],
            'shift_id' => ['nullable', 'integer', 'numeric', 'exists:shifts,id'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['is_working_day', 'shift_id'])) {
                return;
            }

            $day = $this->route('workScheduleDay');
            $working = $this->boolean('is_working_day', $day instanceof WorkScheduleDay ? $day->is_working_day : true);

            if ($working && ! Shift::query()->whereKey($this->input('shift_id'))->where('is_active', true)->exists()) {
                $validator->errors()->add('shift_id', 'A working day requires an active shift.');
            }
        }];
    }
}
