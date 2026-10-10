<?php

namespace App\Http\Requests;

use App\Models\Applicant;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class AuthorizeInterviewBroadcastRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User || $this->user() instanceof Applicant;
    }

    public function rules(): array
    {
        return [
            'socket_id' => ['required', 'string', 'max:100', 'regex:/\A\d+\.\d+\z/'],
            'channel_name' => ['required', 'string', 'max:100', 'regex:/\Aprivate-Recruitment\.Interview\.[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/'],
        ];
    }
}
