<?php

namespace App\Http\Requests;

use App\Models\OvertimeRequest;
use Illuminate\Foundation\Http\FormRequest;

class CancelOvertimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $overtimeRequest = $this->route('overtimeRequest');

        return $overtimeRequest instanceof OvertimeRequest
            && $this->user()?->can('cancel', $overtimeRequest) === true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string'],
        ];
    }
}
