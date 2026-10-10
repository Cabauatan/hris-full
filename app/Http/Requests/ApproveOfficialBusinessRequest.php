<?php

namespace App\Http\Requests;

use App\Models\OfficialBusinessRequest;
use Illuminate\Foundation\Http\FormRequest;

class ApproveOfficialBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        $officialBusinessRequest = $this->route('officialBusinessRequest');

        return $officialBusinessRequest instanceof OfficialBusinessRequest
            && $this->user()?->can('approve', $officialBusinessRequest) === true;
    }

    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string'],
        ];
    }
}
