<?php

namespace App\Http\Requests;

use App\Models\Holiday;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Holiday::class) === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('holidays', 'name')->where(fn (Builder $query) => $query->whereDate('holiday_date', is_string($this->input('holiday_date')) ? $this->input('holiday_date') : null))],
            'holiday_date' => ['required', 'date_format:Y-m-d'],
            'holiday_type' => ['required', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'is_working_day' => ['sometimes', 'boolean'],
            'is_paid' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
