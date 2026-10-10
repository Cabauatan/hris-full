<?php

namespace App\Http\Requests;

use App\Models\CtoRequest;
use Illuminate\Foundation\Http\FormRequest;

class ApproveCtoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ctoRequest = $this->route('ctoRequest');

        return $ctoRequest instanceof CtoRequest
            && $this->user()?->can('approve', $ctoRequest) === true;
    }

    public function rules(): array
    {
        $ctoRequest = $this->route('ctoRequest');

        return [
            'approved_minutes' => ['required', 'integer', 'numeric', 'between:1,2147483648', 'max:'.($ctoRequest instanceof CtoRequest ? $ctoRequest->requested_minutes : 0)],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
