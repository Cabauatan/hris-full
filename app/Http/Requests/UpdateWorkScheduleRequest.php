<?php

namespace App\Http\Requests;

use App\Models\WorkSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workSchedule = $this->route('workSchedule');

        return $workSchedule instanceof WorkSchedule
            && $this->user()?->can('update', $workSchedule) === true;
    }

    public function rules(): array
    {
        $workSchedule = $this->route('workSchedule');

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('work_schedules', 'code')->ignore($workSchedule instanceof WorkSchedule ? $workSchedule : null)],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
