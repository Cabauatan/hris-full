<?php

namespace App\Http\Requests;

use App\Models\EmployeeProfileChangeRequest;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeProfileChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', EmployeeProfileChangeRequest::class) === true;
    }

    public function rules(): array
    {
        return [
            'changes' => ['required', 'array:first_name,middle_name,last_name,suffix,birth_date,gender,civil_status,personal_email,mobile_number,address_line_1,address_line_2,city,province,postal_code,country', 'min:1'],
            'changes.first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'changes.middle_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'changes.last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'changes.suffix' => ['sometimes', 'nullable', 'string', 'max:20'],
            'changes.birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'changes.gender' => ['sometimes', 'nullable', 'string', 'max:30'],
            'changes.civil_status' => ['sometimes', 'nullable', 'string', 'max:30'],
            'changes.personal_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'changes.mobile_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'changes.address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'changes.address_line_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'changes.city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'changes.province' => ['sometimes', 'nullable', 'string', 'max:100'],
            'changes.postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'changes.country' => ['sometimes', 'required', 'string', 'max:100'],
            'reason' => ['nullable', 'string'],
            'document_path' => ['nullable', 'string', 'max:255'],
        ];
    }
}
