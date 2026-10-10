<?php

namespace App\Http\Requests;

use App\Models\AttendanceCorrectionRequest;
use App\Models\DailyAttendance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAttendanceCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AttendanceCorrectionRequest::class) === true;
    }

    public function rules(): array
    {
        return [
            'daily_attendance_id' => ['nullable', 'integer', 'numeric', 'exists:daily_attendances,id'],
            'attendance_date' => ['required', 'date_format:Y-m-d'],
            'requested_time_in' => ['nullable', 'date'],
            'requested_time_out' => ['nullable', 'date'],
            'reason' => ['required', 'string'],
            'document_path' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['daily_attendance_id', 'attendance_date', 'requested_time_in', 'requested_time_out'])) {
                return;
            }

            if (! $this->filled('requested_time_in') && ! $this->filled('requested_time_out')) {
                $validator->errors()->add('requested_time_in', 'A requested time in or requested time out is required.');
            }

            if ($this->filled('requested_time_in') && $this->filled('requested_time_out')
                && CarbonImmutable::parse($this->input('requested_time_out'))->lt(CarbonImmutable::parse($this->input('requested_time_in')))) {
                $validator->errors()->add('requested_time_out', 'The requested time out cannot be earlier than the requested time in.');
            }

            if ($this->filled('daily_attendance_id')
                && ! DailyAttendance::query()->whereKey($this->input('daily_attendance_id'))->whereDate('attendance_date', $this->input('attendance_date'))->exists()) {
                $validator->errors()->add('daily_attendance_id', 'The attendance record must match the requested attendance date.');
            }
        }];
    }
}
