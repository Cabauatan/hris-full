<?php

namespace App\Http\Requests;

use App\Models\EmployeeProfileChangeRequest;
use Illuminate\Foundation\Http\FormRequest;

class RejectEmployeeProfileChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $changeRequest = $this->route(
            'employeeProfileChangeRequest'
        );

        return $changeRequest instanceof EmployeeProfileChangeRequest
            && $this->user()?->can(
                'reject',
                $changeRequest
            ) === true;
    }

    public function rules(): array
    {
        return [
            'remarks' => [
                'nullable',
                'string',
            ],
        ];
    }
}
