<?php

namespace App\Http\Requests;

use App\Models\CtoRequest;
use Illuminate\Foundation\Http\FormRequest;

class RejectCtoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ctoRequest = $this->route('ctoRequest');

        return $ctoRequest instanceof CtoRequest
            && $this->user()?->can('reject', $ctoRequest) === true;
    }

    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string'],
        ];
    }
}
