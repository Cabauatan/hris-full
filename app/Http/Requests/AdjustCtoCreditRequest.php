<?php

namespace App\Http\Requests;

use App\Models\CtoBalance;
use Illuminate\Foundation\Http\FormRequest;

class AdjustCtoCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('adjust', CtoBalance::class) === true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'numeric', 'exists:employees,id'],
            'minutes' => ['required', 'integer', 'numeric', 'between:-2147483648,2147483647', 'not_regex:/^-?0+$/'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
