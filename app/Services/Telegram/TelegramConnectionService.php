<?php

namespace App\Services\Telegram;

use App\Events\TelegramConnectionUpdated;
use App\Exceptions\TelegramRequestException;
use App\Models\Applicant;
use App\Models\TelegramConnection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class TelegramConnectionService
{
    public function __construct(private readonly TelegramClient $client) {}

    /** @return list<array{command: string, description: string}> */
    public function commandMenu(): array
    {
        return [
            ['command' => 'start', 'description' => 'Start the HRIS bot and link your account'],
            ['command' => 'help', 'description' => 'Show available HRIS commands'],
            ['command' => 'status', 'description' => 'Check your HRIS notification connection'],
            ['command' => 'portal', 'description' => 'Open the employee or applicant portal'],
            ['command' => 'unlink', 'description' => 'Disconnect your HRIS notification links'],
            ['command' => 'stop', 'description' => 'Stop HRIS Telegram notifications'],
        ];
    }

    /** @return array{configured: bool, bot_username: ?string, connected: bool, linked_at: ?string} */
    public function status(User|Applicant $actor): array
    {
        $this->activeActor($actor);
        $configured = $this->client->configured();
        $connection = $this->actorConnection($actor)->first();
        $connected = $configured && $connection !== null && $connection->bot_id === (int) config('telegram.bot_id')
            && $connection->chat_id !== null && $connection->linked_at !== null && $connection->unsubscribed_at === null;

        return ['configured' => $configured, 'bot_username' => $configured ? (string) config('telegram.bot_username') : null,
            'connected' => $connected, 'linked_at' => $connected ? $connection->linked_at->toIso8601String() : null];
    }

    /** @return array{url: string, expires_at: string} */
    public function createLink(User|Applicant $actor): array
    {
        $this->activeActor($actor);
        if (! $this->client->configured()) {
            throw new ServiceUnavailableHttpException(null, 'Telegram notifications are not configured.');
        }

        return DB::transaction(function () use ($actor): array {
            $this->activeActor($actor, true);
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $expires = now()->addMinutes(10);
            $connection = $this->actorConnection($actor)->firstOrCreate([], [
                'recipient_type' => $actor instanceof Applicant ? 'applicant' : 'user', 'recipient_id' => $actor->getKey(),
                'connection_key' => (string) Str::uuid(),
            ]);
            $connection = TelegramConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $connection->update(['link_token_hash' => hash('sha256', $token), 'link_token_expires_at' => $expires]);

            return ['url' => 'https://t.me/'.config('telegram.bot_username').'?start='.$token, 'expires_at' => $expires->toIso8601String()];
        });
    }

    public function unlink(User|Applicant $actor): void
    {
        DB::transaction(function () use ($actor): void {
            $this->activeActor($actor, true);
            $connection = $this->actorConnection($actor)->lockForUpdate()->first();
            if ($connection !== null) {
                $this->disconnect($connection);
            }
        });
    }

    /** @param array<string, mixed> $update */
    public function handleUpdate(array $update): void
    {
        $message = $update['message'] ?? null;
        if (! is_array($message) || ($message['chat']['type'] ?? null) !== 'private'
            || ! is_int($message['chat']['id'] ?? null) || $message['chat']['id'] < 1
            || ($message['from']['id'] ?? null) !== $message['chat']['id'] || ($message['from']['is_bot'] ?? null) !== false
            || isset($message['forward_origin']) || isset($message['forward_from']) || isset($message['forward_from_chat'])
            || ! is_string($message['text'] ?? null) || ! $this->client->configured()) {
            return;
        }
        $text = trim($message['text']);
        $suffix = '(?:@'.preg_quote((string) config('telegram.bot_username'), '/').')?';

        if (preg_match('/^\/(start|help|status|portal|unlink|stop)'.$suffix.'$/iD', $text, $command) === 1) {
            $chatId = (string) $message['chat']['id'];
            $name = strtolower($command[1]);

            if (in_array($name, ['stop', 'unlink'], true)) {
                $this->stopChat($chatId);
                $reply = '[HRIS] Telegram notifications have been stopped and your account links disconnected. To reconnect, open your HRIS profile or applicant settings and choose Connect Telegram.';
            } else {
                $reply = match ($name) {
                    'status' => $this->chatStatusMessage($chatId),
                    'portal' => $this->portalMessage($chatId),
                    default => $this->helpMessage(),
                };
            }

            $this->replyAfterCommit($chatId, $reply);

            return;
        }
        if (preg_match('/^\/start'.$suffix.' ([A-Za-z0-9_-]{43})$/iD', $text, $matches) !== 1) {
            return;
        }
        $tokenHash = hash('sha256', $matches[1]);
        $pending = TelegramConnection::query()->where('link_token_hash', $tokenHash)->first();
        if ($pending === null) {
            return;
        }
        DB::transaction(function () use ($pending, $tokenHash, $message): void {
            $actor = match ($pending->recipient_type) {
                'user' => User::query()->lockForUpdate()->find($pending->recipient_id),
                'applicant' => Applicant::query()->lockForUpdate()->find($pending->recipient_id),
                default => null,
            };
            $connection = TelegramConnection::query()->whereKey($pending->id)->where('recipient_type', $pending->recipient_type)
                ->where('recipient_id', $pending->recipient_id)->where('link_token_hash', $tokenHash)->lockForUpdate()->first();
            if ($connection === null || $connection->link_token_expires_at === null || $connection->link_token_expires_at <= now()) {
                return;
            }
            if ($actor === null || ! $actor->is_active) {
                $connection->update(['link_token_hash' => null, 'link_token_expires_at' => null]);

                return;
            }
            $connection->update([
                'bot_id' => (int) config('telegram.bot_id'), 'chat_id' => (string) $message['chat']['id'],
                'connection_key' => (string) Str::uuid(), 'linked_at' => now(), 'unsubscribed_at' => null,
                'link_token_hash' => null, 'link_token_expires_at' => null,
            ]);
            TelegramConnectionUpdated::dispatchIf(config('broadcasting.default') === 'reverb', $connection->recipient_type, $connection->recipient_id);
        });
    }

    public function stopChat(string $chatId): void
    {
        DB::transaction(function () use ($chatId): void {
            foreach (TelegramConnection::query()->where('chat_id', $chatId)->where('bot_id', (int) config('telegram.bot_id'))->orderBy('id')->lockForUpdate()->get() as $connection) {
                $this->disconnect($connection);
            }
        });
    }

    private function helpMessage(): string
    {
        $commands = array_map(fn (array $command): string => '/'.$command['command'].' - '.$command['description'], $this->commandMenu());

        return "[HRIS] Welcome to the HRIS Telegram bot.\n\n".implode("\n", $commands)
            ."\n\nTo receive automatic notifications, sign in to HRIS, open My profile or Applicant settings, and choose Connect Telegram. Use the link generated there to securely link your account.";
    }

    private function chatStatusMessage(string $chatId): string
    {
        $types = $this->connectedAccountTypes($chatId);

        if ($types === []) {
            return '[HRIS] This chat is not connected to an active HRIS account. Open My profile or Applicant settings in HRIS and choose Connect Telegram.';
        }

        $accounts = match ($types) {
            ['user'] => 'your employee account',
            ['applicant'] => 'your applicant account',
            default => 'your employee and applicant accounts',
        };

        return '[HRIS] Telegram notifications are connected for '.$accounts.'. Use /portal to open HRIS, or /stop to stop notifications.';
    }

    private function portalMessage(string $chatId): string
    {
        $url = rtrim((string) config('telegram.frontend_url'), '/');
        $parts = parse_url($url);

        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return '[HRIS] The portal link is currently unavailable. Open HRIS using the address provided by your organization.';
        }

        $types = $this->connectedAccountTypes($chatId);
        $links = [];

        if ($types === [] || in_array('user', $types, true)) {
            $links[] = 'Employee portal: '.$url.'/dashboard';
        }

        if ($types === [] || in_array('applicant', $types, true)) {
            $links[] = 'Applicant portal: '.$url.'/applicant/applications';
        }

        return "[HRIS] Open HRIS and sign in to your account.\n\n".implode("\n", $links);
    }

    /** @return list<string> */
    private function connectedAccountTypes(string $chatId): array
    {
        return TelegramConnection::query()->where('chat_id', $chatId)->where('bot_id', (int) config('telegram.bot_id'))
            ->whereNotNull('linked_at')->whereNull('unsubscribed_at')
            ->where(function (Builder $connections): void {
                $connections->where(fn (Builder $users): Builder => $users->where('recipient_type', 'user')
                    ->whereIn('recipient_id', User::query()->select('id')->where('is_active', true)))
                    ->orWhere(fn (Builder $applicants): Builder => $applicants->where('recipient_type', 'applicant')
                        ->whereIn('recipient_id', Applicant::query()->select('id')->where('is_active', true)));
            })->distinct()->orderBy('recipient_type')->pluck('recipient_type')->all();
    }

    private function replyAfterCommit(#[\SensitiveParameter] string $chatId, #[\SensitiveParameter] string $text): void
    {
        DB::afterCommit(function () use ($chatId, $text): void {
            try {
                $this->client->sendMessage($chatId, $text);
            } catch (TelegramRequestException $exception) {
                Log::warning('Telegram command reply could not be delivered.', [
                    'category' => $exception->category, 'error_code' => $exception->errorCode,
                ]);
            }
        });
    }

    private function disconnect(TelegramConnection $connection): void
    {
        $connection->update(['chat_id' => null, 'linked_at' => null, 'connection_key' => (string) Str::uuid(),
            'link_token_hash' => null, 'link_token_expires_at' => null, 'unsubscribed_at' => now()]);
        TelegramConnectionUpdated::dispatchIf(config('broadcasting.default') === 'reverb', $connection->recipient_type, $connection->recipient_id);
    }

    private function actorConnection(User|Applicant $actor): Builder
    {
        return TelegramConnection::query()->where('recipient_type', $actor instanceof Applicant ? 'applicant' : 'user')->where('recipient_id', $actor->getKey());
    }

    private function activeActor(User|Applicant $actor, bool $lock = false): void
    {
        $query = $actor::query();
        $fresh = ($lock ? $query->lockForUpdate() : $query)->find($actor->getKey());
        if ($fresh === null || ! $fresh->is_active) {
            throw new AuthorizationException('An active account is required.');
        }
    }
}
