<?php

namespace App\Http\Requests;

use App\Models\Applicant;
use App\Models\JobApplication;
use Illuminate\Foundation\Http\FormRequest;

class StoreJobApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Applicant && $this->user()->can('create', JobApplication::class);
    }

    public function rules(): array
    {
        return [
            'job_opening_id' => ['required', 'integer', 'exists:job_openings,id'],
            'cover_letter' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
