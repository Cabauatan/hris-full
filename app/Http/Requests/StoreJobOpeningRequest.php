<?php

namespace App\Http\Requests;

use App\Models\JobOpening;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreJobOpeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->can('create', JobOpening::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:10000'],
            'requirements' => ['nullable', 'string', 'max:10000'],
            'location' => ['nullable', 'string', 'max:180'],
            'employment_type' => ['nullable', 'string', 'max:50'],
            'vacancies' => ['sometimes', 'integer', 'between:1,1000'],
            'status' => ['sometimes', 'required', Rule::in(['open', 'closed'])],
            'closes_on' => ['nullable', 'date_format:Y-m-d'],
            'monthly_salary' => ['required', 'numeric', 'regex:/^\d+(?:\.\d{1,2})?$/', 'min:0.01', 'max:9999999999999.99'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->input('status', 'open') === 'open' && $this->filled('closes_on')
                && $this->input('closes_on') < now()->toDateString()) {
                $validator->errors()->add('closes_on', 'An open position must close today or later.');
            }
        }];
    }
}
