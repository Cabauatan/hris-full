<?php

namespace App\Http\Requests;

use App\Policies\WorkspacePolicy;
use Illuminate\Validation\Rule;

class GetCalendarRequest extends WorkspaceDateRangeRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        if ($this->user()?->can('viewCalendar') !== true || ! $this->canUseScope()) {
            return false;
        }

        $types = $this->input('types');

        if (is_array($types)) {
            foreach ($types as $type) {
                if (is_string($type) && array_key_exists($type, WorkspacePolicy::CALENDAR_PERMISSIONS) && ! $this->user()->can('viewCalendarType', $type)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'types' => ['sometimes', 'array', 'list', 'max:'.count(WorkspacePolicy::CALENDAR_PERMISSIONS)],
            'types.*' => ['string', 'distinct', Rule::in(array_keys(WorkspacePolicy::CALENDAR_PERMISSIONS))],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('types') && in_array($this->input('types'), [null, ''], true)) {
            $this->merge(['types' => []]);
        }
    }

    protected function maximumDays(): int
    {
        return 62;
    }
}
