<?php

namespace App\Http\Requests;

use App\Models\OvertimeRequest;
use Illuminate\Foundation\Http\FormRequest;

class ApproveOvertimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $overtimeRequest = $this->route('overtimeRequest');

        return $overtimeRequest instanceof OvertimeRequest
            && $this->user()?->can('approve', $overtimeRequest) === true;
    }

    public function rules(): array
    {
        $overtimeRequest = $this->route('overtimeRequest');

        return [
            'approved_minutes' => ['required', 'integer', 'numeric', 'between:1,4294967295', 'max:'.($overtimeRequest instanceof OvertimeRequest ? $overtimeRequest->requested_minutes : 0)],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
