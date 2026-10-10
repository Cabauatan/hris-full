<?php

namespace App\Services\Notification;

use App\Models\User;
use App\Notifications\HrisNotification;
use Illuminate\Notifications\Channels\BroadcastChannel;
use Illuminate\Notifications\DatabaseNotification;

class IdempotentDatabaseChannel
{
    public function send(User $notifiable, HrisNotification $notification): DatabaseNotification
    {
        $data = $notification->toDatabase($notifiable);

        $stored = $notifiable->notifications()->firstOrCreate(['id' => $notification->id], [
            'type' => HrisNotification::class,
            'data' => $data,
            'read_at' => null,
            'created_at' => $data['occurred_at'],
        ]);

        if ($stored->wasRecentlyCreated && config('broadcasting.default') === 'reverb') {
            app(BroadcastChannel::class)->send($notifiable, $notification);
        }

        return $stored;
    }
}
