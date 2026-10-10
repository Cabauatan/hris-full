<?php

namespace App\Http\Requests;

use App\Models\EmployeeProfileChangeRequest;
use Illuminate\Foundation\Http\FormRequest;

class ApplyEmployeeProfileChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employeeProfileChangeRequest = $this->route('employeeProfileChangeRequest');

        return $employeeProfileChangeRequest instanceof EmployeeProfileChangeRequest
            && $this->user()?->can('apply', $employeeProfileChangeRequest) === true;
    }

    public function rules(): array
    {
        return [];
    }
}
