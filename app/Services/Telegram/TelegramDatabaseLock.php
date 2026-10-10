<?php

namespace App\Services\Telegram;

use Illuminate\Cache\DatabaseLock;

class TelegramDatabaseLock extends DatabaseLock
{
    /**
     * @param  int|null  $seconds
     */
    public function refresh($seconds = null): bool
    {
        return $this->connection->transaction(function () use ($seconds): bool {
            $lease = $this->connection->table($this->table)->where('key', $this->name)->where('owner', $this->owner)
                ->where('expiration', '>', $this->currentTime())->lockForUpdate()->first();
            if ($lease === null) {
                return false;
            }
            $this->connection->table($this->table)->where('key', $this->name)->where('owner', $this->owner)
                ->update(['expiration' => $this->expiresAt($seconds)]);

            return true;
        });
    }
}
