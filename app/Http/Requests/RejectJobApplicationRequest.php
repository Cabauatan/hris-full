<?php

namespace App\Http\Requests;

use App\Models\JobApplication;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class RejectJobApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $application = $this->route('jobApplication');

        return $application instanceof JobApplication && $this->user() instanceof User
            && $this->user()->can('reject', $application);
    }

    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
