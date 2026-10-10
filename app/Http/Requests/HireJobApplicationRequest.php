<?php

namespace App\Http\Requests;

use App\Models\JobApplication;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class HireJobApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $application = $this->route('jobApplication');

        return $application instanceof JobApplication && $this->user() instanceof User
            && $this->user()->can('hire', $application);
    }

    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
