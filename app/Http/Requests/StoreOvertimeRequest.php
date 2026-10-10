<?php

namespace App\Http\Requests;

use App\Models\OvertimeRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOvertimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', OvertimeRequest::class) === true;
    }

    public function rules(): array
    {
        return [
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', Rule::when(is_string($this->input('start_at')), 'after:start_at')],
            'reason' => ['required', 'string'],
            'document_path' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['start_at', 'end_at'])) {
                return;
            }

            $minutes = (int) CarbonImmutable::parse($this->input('start_at'))->diffInMinutes(CarbonImmutable::parse($this->input('end_at')));

            if ($minutes < 1 || $minutes > 4294967295) {
                $validator->errors()->add('end_at', 'The requested duration must be between 1 and 4294967295 complete minutes.');
            }
        }];
    }
}
