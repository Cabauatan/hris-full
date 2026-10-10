<?php

namespace App\Services\Telegram;

use App\Exceptions\TelegramRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TelegramClient
{
    public function configured(): bool
    {
        return (bool) config('telegram.enabled') && (int) config('telegram.bot_id') > 0
            && preg_match('/^[A-Za-z0-9_]{5,32}$/D', (string) config('telegram.bot_username')) === 1
            && preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/D', (string) config('telegram.bot_token')) === 1;
    }

    /** @return array{id: int, username: string} */
    public function identity(): array
    {
        if (! $this->configured()) {
            throw new TelegramRequestException('configuration');
        }
        $identity = Cache::remember('hris-telegram-identity-'.hash('sha256', (string) config('telegram.bot_token')), 300, function (): array {
            $result = $this->request('getMe');
            if (! is_array($result) || ! is_int($result['id'] ?? null) || ($result['is_bot'] ?? null) !== true || ! is_string($result['username'] ?? null)) {
                throw new TelegramRequestException('identity');
            }

            return ['id' => $result['id'], 'username' => $result['username']];
        });
        if ($identity['id'] !== (int) config('telegram.bot_id') || strcasecmp($identity['username'], (string) config('telegram.bot_username')) !== 0) {
            throw new TelegramRequestException('identity');
        }

        return $identity;
    }

    /** @return array<string, mixed> */
    public function webhookInfo(): array
    {
        $this->identity();
        $result = $this->request('getWebhookInfo');
        if (! is_array($result) || ! is_string($result['url'] ?? null)) {
            throw new TelegramRequestException('protocol');
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function updates(int $offset, int $timeout): array
    {
        $this->identity();
        $result = $this->request('getUpdates', ['offset' => $offset, 'timeout' => $timeout, 'limit' => 100, 'allowed_updates' => ['message']]);
        if (! is_array($result) || ! array_is_list($result)) {
            throw new TelegramRequestException('protocol');
        }

        return $result;
    }

    public function sendMessage(#[\SensitiveParameter] string $chatId, #[\SensitiveParameter] string $text): void
    {
        $this->identity();
        $result = $this->request('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'link_preview_options' => ['is_disabled' => true]]);
        if (! is_array($result) || ! is_int($result['message_id'] ?? null)) {
            throw new TelegramRequestException('protocol');
        }
    }

    /** @param list<array{command: string, description: string}> $commands */
    public function setCommands(array $commands): void
    {
        $this->identity();
        $result = $this->request('setMyCommands', [
            'commands' => $commands, 'scope' => ['type' => 'all_private_chats'], 'language_code' => '',
        ]);
        if ($result !== true) {
            throw new TelegramRequestException('protocol');
        }
    }

    /** @return list<array{command: string, description: string}> */
    public function commands(): array
    {
        $this->identity();
        $result = $this->request('getMyCommands', ['scope' => ['type' => 'all_private_chats'], 'language_code' => '']);
        if (! is_array($result) || ! array_is_list($result) || count($result) > 100) {
            throw new TelegramRequestException('protocol');
        }
        $commands = [];
        foreach ($result as $command) {
            if (! is_array($command) || ! is_string($command['command'] ?? null) || ! is_string($command['description'] ?? null)
                || preg_match('/^[a-z0-9_]{1,32}$/D', $command['command']) !== 1
                || mb_strlen($command['description']) < 1 || mb_strlen($command['description']) > 256) {
                throw new TelegramRequestException('protocol');
            }
            $commands[] = ['command' => $command['command'], 'description' => $command['description']];
        }

        return $commands;
    }

    /** @param array<string, mixed> $payload */
    private function request(string $method, #[\SensitiveParameter] array $payload = []): mixed
    {
        if (! $this->configured()) {
            throw new TelegramRequestException('configuration');
        }
        try {
            $response = Http::connectTimeout(5)->timeout(25)->asJson()->post(
                'https://api.telegram.org/bot'.config('telegram.bot_token').'/'.$method, $payload,
            );
        } catch (ConnectionException) {
            throw new TelegramRequestException('connection');
        } catch (RequestException $exception) {
            throw new TelegramRequestException('transport', $exception->response->status());
        }
        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || ($body['ok'] ?? null) !== true) {
            $errorCode = is_array($body) && is_int($body['error_code'] ?? null) ? $body['error_code'] : $response->status();
            $delay = is_array($body) ? ($body['parameters']['retry_after'] ?? null) : null;

            throw new TelegramRequestException('api', $errorCode, is_int($delay) && $delay > 0 ? min($delay, 3600) : null);
        }

        return $body['result'] ?? null;
    }
}
