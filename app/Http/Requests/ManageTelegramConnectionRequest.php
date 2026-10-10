<?php

namespace App\Http\Requests;

use App\Models\Applicant;
use App\Models\TelegramConnection;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ManageTelegramConnectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $actor = $this->user();

        return ($actor instanceof User || $actor instanceof Applicant)
            && Gate::forUser($actor)->allows('manage', [TelegramConnection::class, $actor]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<string>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['prohibited'],
            'applicant_id' => ['prohibited'],
            'recipient_id' => ['prohibited'],
            'recipient_type' => ['prohibited'],
            'chat_id' => ['prohibited'],
            'bot_id' => ['prohibited'],
            'token' => ['prohibited'],
        ];
    }
}
