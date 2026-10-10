<?php

namespace App\Http\Requests;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(
            'create',
            LeaveRequest::class
        ) === true;
    }

    public function rules(): array
    {
        return [
            'leave_type_id' => [
                'required',
                'integer',
                'numeric',
                'exists:leave_types,id',
            ],

            'start_date' => [
                'required',
                'date_format:Y-m-d',
            ],

            'end_date' => [
                'required',
                'date_format:Y-m-d',
                Rule::when(is_string($this->input('start_date')), 'after_or_equal:start_date'),
            ],

            'day_portion' => [
                'required',
                'string',
                'max:20',
            ],

            'requested_units' => [
                'required',
                'numeric',
                'gt:0',
                'max:999999.99',
                'regex:/^\d+(?:\.\d{1,2})?$/',
            ],

            'reason' => [
                'required',
                'string',
            ],

            'document_path' => [
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (
                    $validator->errors()->has('leave_type_id')
                    || $validator->errors()->has('day_portion')
                    || $validator->errors()->has('document_path')
                ) {
                    return;
                }

                $leaveType = LeaveType::query()->find(
                    $this->integer('leave_type_id')
                );

                if (! $leaveType) {
                    return;
                }

                if (
                    $leaveType->requires_balance
                    && ! $validator->errors()->hasAny(['start_date', 'end_date'])
                    && CarbonImmutable::parse($this->input('start_date'))->year !== CarbonImmutable::parse($this->input('end_date'))->year
                ) {
                    $validator->errors()->add(
                        'end_date',
                        'Balance-managed leave must fall within one calendar year.'
                    );
                }

                if (! $leaveType->is_active) {
                    $validator->errors()->add(
                        'leave_type_id',
                        'The selected leave type is not active.'
                    );
                }

                if (
                    $this->input('day_portion') !== 'full_day'
                    && ! $leaveType->allows_partial_day
                ) {
                    $validator->errors()->add(
                        'day_portion',
                        'The selected leave type does not allow partial-day leave.'
                    );
                }

                if (
                    $leaveType->requires_document
                    && ! $this->filled('document_path')
                ) {
                    $validator->errors()->add(
                        'document_path',
                        'A supporting document is required for this leave type.'
                    );
                }
            },
        ];
    }
}
