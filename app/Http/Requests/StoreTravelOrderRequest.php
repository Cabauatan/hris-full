<?php

namespace App\Http\Requests;

use App\Models\TravelOrderRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTravelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', TravelOrderRequest::class) === true;
    }

    public function rules(): array
    {
        return [
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', Rule::when(is_string($this->input('start_at')), 'after:start_at')],
            'destination' => ['required', 'string', 'max:255'],
            'purpose' => ['required', 'string'],
            'document_path' => ['nullable', 'string', 'max:255'],
        ];
    }
}
