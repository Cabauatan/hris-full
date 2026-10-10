<?php

namespace App\Http\Requests;

use App\Services\Report\ReportService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GetReportRequest extends WorkspaceDateRangeRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $report = $this->route('report');

        return is_string($report) && $this->user()?->can($this->ability(), [$report]) === true && $this->canUseScope();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $definition = app(ReportService::class)->definition($this->route('report'));
        $rules = parent::rules();
        $rules['scope'] = ['sometimes', 'string', Rule::in(['mine', 'visible'])];
        $rules['employee_id'] = ['sometimes', 'integer', 'numeric', 'min:1'];

        if ($definition['date_basis'] === null) {
            $rules['start'] = ['prohibited'];
            $rules['end'] = ['prohibited'];
        }

        $rules['year'] = $definition['year_filter'] ? ['sometimes', 'integer', 'numeric', 'between:1900,9999'] : ['prohibited'];
        $rules['status'] = $definition['statuses'] === [] ? ['prohibited'] : ['sometimes', 'string', Rule::in($definition['statuses'])];
        $rules['payroll_run_id'] = $definition['key'] === 'payroll' ? ['sometimes', 'integer', 'numeric', 'min:1'] : ['prohibited'];

        return $rules + $this->paginationRules();
    }

    public function after(): array
    {
        $rules = $this->rules();
        $dateValidators = in_array('prohibited', $rules['start'], true) ? [] : parent::after();

        return [...$dateValidators, function (Validator $validator) use ($rules): void {
            foreach (array_keys($this->all()) as $field) {
                if (! array_key_exists($field, $rules) || in_array('prohibited', $rules[$field], true)) {
                    $validator->errors()->add($field, 'The '.$field.' field is not allowed for this report.');
                }
            }
        }];
    }

    protected function ability(): string
    {
        return 'viewReport';
    }

    /** @return array<string, array<mixed>> */
    protected function paginationRules(): array
    {
        return ['per_page' => ['sometimes', 'integer', 'numeric', 'between:1,100'], 'page' => ['sometimes', 'integer', 'numeric', 'min:1']];
    }
}
