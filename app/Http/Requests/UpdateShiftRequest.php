<?php

namespace App\Http\Requests;

use App\Models\Shift;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        $shift = $this->route('shift');

        return $shift instanceof Shift
            && $this->user()?->can('update', $shift) === true;
    }

    public function rules(): array
    {
        $shift = $this->route('shift');

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('shifts', 'code')->ignore($shift instanceof Shift ? $shift : null)],
            'name' => ['required', 'string', 'max:100'],
            'start_time' => ['required', 'string', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/'],
            'end_time' => ['required', 'string', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/'],
            'break_minutes' => ['sometimes', 'integer', 'numeric', 'between:0,65535'],
            'late_grace_minutes' => ['sometimes', 'integer', 'numeric', 'between:0,65535'],
            'undertime_grace_minutes' => ['sometimes', 'integer', 'numeric', 'between:0,65535'],
            'is_overnight' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'remarks' => ['nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['start_time', 'end_time', 'is_overnight'])) {
                return;
            }

            $shift = $this->route('shift');
            $overnight = $this->boolean('is_overnight', $shift instanceof Shift && $shift->is_overnight);
            $start = strlen($this->input('start_time')) === 5 ? $this->input('start_time').':00' : $this->input('start_time');
            $end = strlen($this->input('end_time')) === 5 ? $this->input('end_time').':00' : $this->input('end_time');

            if (! $overnight && $end <= $start) {
                $validator->errors()->add('end_time', 'The end time must be after the start time for a same-day shift.');
            }
        }];
    }
}
