<?php

namespace App\Http\Requests;

use App\Services\Notification\NotificationInboxService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Notifications\DatabaseNotification;

class ReadNotificationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(NotificationInboxService $service): bool
    {
        $user = $this->user();
        $id = $this->route('notification');

        if ($id instanceof DatabaseNotification) {
            $id = $id->getKey();
        }

        if ($user === null || ! is_string($id)) {
            return false;
        }

        $notification = $service->find($user, $id);
        $this->route()->setParameter('notification', $notification);

        return $user->can('update', $notification);
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
