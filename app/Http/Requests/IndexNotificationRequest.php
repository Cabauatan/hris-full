<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\Rule;

class IndexNotificationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', DatabaseNotification::class) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'string', Rule::in(['all', 'unread'])],
            'page' => ['sometimes', 'integer', 'numeric', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'numeric', 'between:1,100'],
            'user_id' => ['prohibited'],
            'notifiable_id' => ['prohibited'],
            'notifiable_type' => ['prohibited'],
        ];
    }
}
