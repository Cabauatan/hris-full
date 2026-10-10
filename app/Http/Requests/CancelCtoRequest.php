<?php

namespace App\Http\Requests;

use App\Models\CtoRequest;
use Illuminate\Foundation\Http\FormRequest;

class CancelCtoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ctoRequest = $this->route('ctoRequest');

        return $ctoRequest instanceof CtoRequest
            && $this->user()?->can('cancel', $ctoRequest) === true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string'],
        ];
    }
}
