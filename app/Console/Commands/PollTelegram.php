<?php

namespace App\Console\Commands;

use App\Exceptions\TelegramRequestException;
use App\Models\TelegramBotState;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramConnectionService;
use App\Services\Telegram\TelegramDatabaseLock;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Throwable;

#[Signature('telegram:poll {--once : Process one update batch without waiting}')]
#[Description('Receive Telegram account links and HRIS bot commands')]
class PollTelegram extends Command
{
    public function handle(TelegramClient $client, TelegramConnectionService $connections): int
    {
        if (! $client->configured()) {
            $this->error('Telegram notifications are not configured.');

            return self::FAILURE;
        }
        $lockName = 'hris-telegram-poll-'.(int) config('telegram.bot_id');
        $cache = Cache::store();
        $store = $cache->getStore();
        $lockConfig = config('cache.stores.'.config('cache.default'), []);
        $lock = $store instanceof DatabaseStore
            ? new TelegramDatabaseLock(
                $store->getLockConnection() ?? $store->getConnection(), $lockConfig['lock_table'] ?? 'cache_locks',
                $store->getPrefix().$lockName, 90, null, $lockConfig['lock_lottery'] ?? [2, 100], $lockConfig['lock_timeout'] ?? 86400,
            )
            : $cache->lock($lockName, 90);
        if (! $lock->get()) {
            $this->error('A Telegram polling process is already running.');

            return self::FAILURE;
        }
        try {
            $identity = $client->identity();
            if ($client->webhookInfo()['url'] !== '') {
                $this->error('Telegram has an existing webhook. Polling was not started.');

                return self::FAILURE;
            }
            $state = TelegramBotState::query()->firstOrCreate(['bot_id' => $identity['id']], ['next_update_id' => 0]);
            $failures = 0;
            do {
                if (! $lock->refresh(90)) {
                    $this->error('Telegram polling lock was lost.');

                    return self::FAILURE;
                }
                try {
                    $updates = $client->updates($state->next_update_id, $this->option('once') ? 0 : 20);
                    $failures = 0;
                } catch (TelegramRequestException $exception) {
                    $retryable = $exception->category === 'connection' || $exception->errorCode === 429
                        || ($exception->errorCode >= 500 && $exception->errorCode <= 599);
                    if ($this->option('once') || ! $retryable) {
                        throw $exception;
                    }
                    $failures++;
                    $delay = $exception->errorCode === 429 ? ($exception->retryAfter ?? 30) : min(60, 5 * (2 ** min($failures - 1, 4)));
                    $this->warn('Telegram polling is temporarily unavailable. Retrying after a delay.');
                    for ($remaining = $delay; $remaining > 0; $remaining -= $pause) {
                        if (! $lock->refresh(90)) {
                            throw new TelegramRequestException('poll_lock');
                        }
                        $pause = min(30, $remaining);
                        Sleep::for($pause)->seconds();
                    }
                    $state = $state->fresh();

                    continue;
                }
                if (! $lock->refresh(90)) {
                    $this->error('Telegram polling lock was lost.');

                    return self::FAILURE;
                }
                foreach ($updates as $update) {
                    if (! is_array($update) || ! is_int($update['update_id'] ?? null) || $update['update_id'] < 0) {
                        throw new TelegramRequestException('protocol');
                    }
                    $state = DB::transaction(function () use ($update, $connections, $state, $lock): TelegramBotState {
                        $current = TelegramBotState::query()->lockForUpdate()->findOrFail($state->bot_id);
                        if (! $lock->refresh(90)) {
                            throw new TelegramRequestException('poll_lock');
                        }
                        if ($update['update_id'] < $current->next_update_id) {
                            return $current;
                        }
                        $connections->handleUpdate($update);
                        if (! $lock->refresh(90)) {
                            throw new TelegramRequestException('poll_lock');
                        }
                        $current->update(['next_update_id' => $update['update_id'] + 1]);

                        return $current;
                    });
                    if (! $lock->refresh(90)) {
                        $this->error('Telegram polling lock was lost.');

                        return self::FAILURE;
                    }
                }
            } while (! $this->option('once'));

            return self::SUCCESS;
        } catch (TelegramRequestException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Telegram polling failed. The last committed cursor is preserved.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
