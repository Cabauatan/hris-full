<?php

namespace App\Http\Requests;

use App\Models\TimeLog;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTimeLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        $timeLog = $this->route('timeLog');

        return $timeLog instanceof TimeLog
            && $this->user()?->can('update', $timeLog) === true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'numeric', 'exists:employees,id'],
            'logged_at' => ['required', 'date'],
            'log_type' => ['nullable', 'string', 'max:20'],
            'source' => ['sometimes', 'string', 'max:30'],
            'device_code' => ['nullable', 'string', 'max:100'],
            'external_reference' => ['nullable', 'string', 'max:150'],
            'reason' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
            'is_valid' => ['sometimes', 'boolean'],
        ];
    }
}
