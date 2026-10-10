<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Notifications\DatabaseNotification;

class ReadAllNotificationsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('markAllAsRead', DatabaseNotification::class) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'read_at' => ['prohibited'],
            'data' => ['prohibited'],
            'notifiable_id' => ['prohibited'],
            'notifiable_type' => ['prohibited'],
        ];
    }
}
