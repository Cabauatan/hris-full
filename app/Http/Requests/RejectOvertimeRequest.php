<?php

namespace App\Http\Requests;

use App\Models\OvertimeRequest;
use Illuminate\Foundation\Http\FormRequest;

class RejectOvertimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $overtimeRequest = $this->route('overtimeRequest');

        return $overtimeRequest instanceof OvertimeRequest
            && $this->user()?->can('reject', $overtimeRequest) === true;
    }

    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string'],
        ];
    }
}
