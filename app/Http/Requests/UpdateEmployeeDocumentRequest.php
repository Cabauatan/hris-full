<?php

namespace App\Http\Requests;

use App\Models\EmployeeDocument;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateEmployeeDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employeeDocument = $this->route('employeeDocument');

        return $employeeDocument instanceof EmployeeDocument
            && $this->user()?->can('update', $employeeDocument) === true;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:200'],
            'file' => ['sometimes', 'file', 'max:10240'],
            'issued_at' => ['nullable', 'date_format:Y-m-d'],
            'expires_at' => ['nullable', 'date_format:Y-m-d'],
            'remarks' => ['nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['issued_at', 'expires_at'])) {
                return;
            }

            $document = $this->route('employeeDocument');
            $issuedAt = $this->exists('issued_at') ? $this->input('issued_at') : ($document instanceof EmployeeDocument ? $document->issued_at : null);
            $expiresAt = $this->exists('expires_at') ? $this->input('expires_at') : ($document instanceof EmployeeDocument ? $document->expires_at : null);

            if ($issuedAt !== null && $expiresAt !== null && CarbonImmutable::parse($expiresAt)->lt(CarbonImmutable::parse($issuedAt))) {
                $validator->errors()->add('expires_at', 'The expiry date must be on or after the issue date.');
            }
        }];
    }
}
