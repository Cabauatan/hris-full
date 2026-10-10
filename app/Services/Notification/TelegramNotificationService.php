<?php

namespace App\Services\Notification;

use App\Events\TelegramConnectionUpdated;
use App\Exceptions\TelegramRequestException;
use App\Jobs\DeliverHrisTelegram;
use App\Models\Applicant;
use App\Models\TelegramConnection;
use App\Models\TelegramDelivery;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

class TelegramNotificationService
{
    public function __construct(private readonly HrisMessageService $messages, private readonly TelegramClient $client) {}

    public function queue(string $resourceType, int $resourceId, string $event, User|Applicant $recipient, ?string $eventId = null, ?string $sourceVersion = null): void
    {
        if (! $this->client->configured()) {
            return;
        }
        if (! $this->messages->supports($resourceType, $event) || $resourceId < 1 || ! $recipient->exists || $recipient->getKey() < 1
            || ($eventId !== null && ($eventId === '' || strlen($eventId) > 180))
            || ($sourceVersion !== null && strlen($sourceVersion) > 120)) {
            throw new InvalidArgumentException('Unsupported HRIS Telegram delivery.');
        }
        $recipientType = $recipient instanceof Applicant ? 'applicant' : 'user';
        $fresh = $recipient::query()->find($recipient->getKey());
        $connection = TelegramConnection::query()->where('recipient_type', $recipientType)->where('recipient_id', $recipient->getKey())
            ->where('bot_id', (int) config('telegram.bot_id'))->whereNotNull('chat_id')->whereNotNull('linked_at')->whereNull('unsubscribed_at')->first();
        if ($fresh === null || ! $fresh->is_active || $connection === null) {
            return;
        }
        $eventId ??= (string) Str::uuid();
        $identity = json_encode([$eventId, $resourceType, $resourceId, $event, $recipientType, (int) $recipient->getKey(), $connection->connection_key], JSON_THROW_ON_ERROR);
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'hris-telegram/'.$identity)->toString();
        $delivery = TelegramDelivery::query()->firstOrCreate(['id' => $id], [
            'resource_type' => $resourceType, 'resource_id' => $resourceId, 'event' => $event, 'event_id' => $eventId,
            'recipient_type' => $recipientType, 'recipient_id' => $recipient->getKey(), 'connection_key' => $connection->connection_key,
            'source_version' => $sourceVersion, 'occurred_at' => now(), 'status' => 'queued',
        ]);
        if ($delivery->wasRecentlyCreated) {
            dispatch((new DeliverHrisTelegram($delivery->id))->onQueue('notifications')->afterCommit());
        }
    }

    public function deliver(string $deliveryId): void
    {
        $hint = TelegramDelivery::query()->find($deliveryId);
        $connectionHint = $hint === null ? null : TelegramConnection::query()->where('recipient_type', $hint->recipient_type)->where('recipient_id', $hint->recipient_id)
            ->where('connection_key', $hint->connection_key)->where('bot_id', (int) config('telegram.bot_id'))
            ->whereNotNull('chat_id')->whereNotNull('linked_at')->whereNull('unsubscribed_at')->first();

        DB::transaction(function () use ($deliveryId, $connectionHint): void {
            $delivery = TelegramDelivery::query()->lockForUpdate()->find($deliveryId);
            if ($delivery === null || in_array($delivery->status, ['sent', 'skipped'], true)) {
                return;
            }
            $connection = null;
            if ($connectionHint !== null) {
                TelegramConnection::query()->where('chat_id', $connectionHint->chat_id)->where('bot_id', $connectionHint->bot_id)->orderBy('id')->lockForUpdate()->get();
                $connection = TelegramConnection::query()->whereKey($connectionHint->id)->where('recipient_type', $delivery->recipient_type)->where('recipient_id', $delivery->recipient_id)
                    ->where('connection_key', $delivery->connection_key)->where('chat_id', $connectionHint->chat_id)
                    ->where('bot_id', (int) config('telegram.bot_id'))->whereNotNull('linked_at')->whereNull('unsubscribed_at')->lockForUpdate()->first();
            }
            $recipient = match ($delivery->recipient_type) {
                'user' => User::query()->find($delivery->recipient_id),
                'applicant' => Applicant::query()->find($delivery->recipient_id),
                default => null,
            };
            $message = $recipient === null ? null : $this->messages->resolve($delivery->resource_type, $delivery->resource_id, $delivery->event, $recipient, $delivery->source_version);
            $frontendUrl = rtrim((string) config('telegram.frontend_url'), '/');
            $parts = parse_url($frontendUrl);
            if (! $this->client->configured() || $connection === null || $message === null || $parts === false
                || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                $delivery->update(['status' => 'skipped']);

                return;
            }
            try {
                $this->client->sendMessage($connection->chat_id, '[HRIS] '.$message['title']."\n\n".$message['message']."\n\n".$frontendUrl.$message['path']);
            } catch (TelegramRequestException $exception) {
                if ($exception->errorCode !== 403) {
                    throw $exception;
                }
                foreach (TelegramConnection::query()->where('chat_id', $connection->chat_id)->where('bot_id', $connection->bot_id)->lockForUpdate()->get() as $blocked) {
                    $blocked->update(['chat_id' => null, 'linked_at' => null, 'unsubscribed_at' => now(),
                        'link_token_hash' => null, 'link_token_expires_at' => null, 'connection_key' => (string) Str::uuid()]);
                    TelegramConnectionUpdated::dispatchIf(config('broadcasting.default') === 'reverb', $blocked->recipient_type, $blocked->recipient_id);
                }
                $delivery->update(['status' => 'skipped']);

                return;
            }
            $delivery->update(['status' => 'sent', 'sent_at' => now()]);
        });
    }

    public function failed(string $deliveryId): void
    {
        TelegramDelivery::query()->whereKey($deliveryId)->whereNotIn('status', ['sent', 'skipped'])->update(['status' => 'failed']);
    }
}
