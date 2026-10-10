<?php

namespace App\Http\Requests;

use App\Models\Holiday;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        $holiday = $this->route('holiday');

        return $holiday instanceof Holiday
            && $this->user()?->can('update', $holiday) === true;
    }

    public function rules(): array
    {
        $holiday = $this->route('holiday');

        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('holidays', 'name')->where(fn (Builder $query) => $query->whereDate('holiday_date', is_string($this->input('holiday_date')) ? $this->input('holiday_date') : null))->ignore($holiday instanceof Holiday ? $holiday : null)],
            'holiday_date' => ['required', 'date_format:Y-m-d'],
            'holiday_type' => ['required', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'is_working_day' => ['sometimes', 'boolean'],
            'is_paid' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
