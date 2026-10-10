<?php

namespace App\Services\Notification;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationInboxService
{
    /**
     * @param  array{filter?: string, page?: int, per_page?: int}  $filters
     */
    public function paginate(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = $user->notifications()->reorder()->orderByDesc('created_at')->orderByDesc('id');

        if (($filters['filter'] ?? 'all') === 'unread') {
            $query->whereNull('read_at');
        }

        return $query->paginate(
            max(1, min(100, (int) ($filters['per_page'] ?? 15))),
            ['*'],
            'page',
            max(1, (int) ($filters['page'] ?? 1)),
        )->withQueryString();
    }

    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    public function find(User $user, string $id): DatabaseNotification
    {
        return $user->notifications()->whereKey($id)->firstOrFail();
    }

    public function markRead(User $user, DatabaseNotification $notification): DatabaseNotification
    {
        $user->notifications()->whereKey($notification->getKey())->whereNull('read_at')->update([
            'read_at' => now(),
        ]);

        return $this->find($user, $notification->getKey());
    }

    public function markAllRead(User $user): int
    {
        return $user->unreadNotifications()->update(['read_at' => now()]);
    }
}
