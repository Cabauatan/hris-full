<?php

namespace App\Http\Requests;

use App\Models\JobOpening;
use App\Models\User;
use Illuminate\Validation\Rule;

class UpdateJobOpeningRequest extends StoreJobOpeningRequest
{
    public function authorize(): bool
    {
        $opening = $this->route('jobOpening');

        return $opening instanceof JobOpening && $this->user() instanceof User
            && $this->user()->can('update', $opening);
    }

    public function rules(): array
    {
        return array_replace(parent::rules(), ['status' => ['required', Rule::in(['open', 'closed'])]]);
    }
}
