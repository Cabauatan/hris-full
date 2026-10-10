<?php

namespace App\Services\Notification;

use App\Jobs\DeliverHrisEmail;
use App\Mail\HrisUpdateMail;
use App\Models\Applicant;
use App\Models\EmailDelivery;
use App\Models\User;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

class EmailNotificationService
{
    public function __construct(private readonly HrisMessageService $messages) {}

    public function queue(
        string $resourceType,
        int $resourceId,
        string $event,
        User|Applicant $recipient,
        ?string $eventId = null,
        ?string $sourceVersion = null,
    ): void {
        if (! config('hris_mail.enabled')) {
            return;
        }
        if (! $this->messages->supports($resourceType, $event) || $resourceId < 1 || ! $recipient->exists || $recipient->getKey() < 1
            || ($eventId !== null && ($eventId === '' || strlen($eventId) > 180))
            || ($sourceVersion !== null && strlen($sourceVersion) > 120)) {
            throw new InvalidArgumentException('Unsupported HRIS email delivery.');
        }

        $eventId ??= (string) Str::uuid();
        $recipientType = $recipient instanceof Applicant ? 'applicant' : 'user';
        $identity = json_encode([$eventId, $resourceType, $resourceId, $event, $recipientType, (int) $recipient->getKey()], JSON_THROW_ON_ERROR);
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'hris-email/'.$identity)->toString();
        $delivery = EmailDelivery::query()->firstOrCreate(['id' => $id], [
            'resource_type' => $resourceType, 'resource_id' => $resourceId,
            'event' => $event, 'event_id' => $eventId, 'recipient_type' => $recipientType,
            'recipient_id' => (int) $recipient->getKey(), 'source_version' => $sourceVersion,
            'occurred_at' => now(), 'status' => 'queued',
        ]);

        if ($delivery->wasRecentlyCreated) {
            dispatch((new DeliverHrisEmail($delivery->id))->onQueue('notifications')->afterCommit());
        }
    }

    public function deliver(string $deliveryId): void
    {
        DB::transaction(function () use ($deliveryId): void {
            $delivery = EmailDelivery::query()->lockForUpdate()->find($deliveryId);
            if ($delivery === null || in_array($delivery->status, ['sent', 'skipped'], true)) {
                return;
            }
            if (! config('hris_mail.enabled')) {
                $delivery->update(['status' => 'skipped']);

                return;
            }

            $recipient = match ($delivery->recipient_type) {
                'user' => User::query()->find($delivery->recipient_id),
                'applicant' => Applicant::query()->find($delivery->recipient_id),
                default => null,
            };
            $message = $recipient === null || ! $recipient->is_active || ! filter_var($recipient->email, FILTER_VALIDATE_EMAIL)
                ? null : $this->messages->resolve($delivery->resource_type, $delivery->resource_id, $delivery->event, $recipient, $delivery->source_version);
            $frontendUrl = rtrim((string) config('hris_mail.frontend_url'), '/');
            $urlParts = parse_url($frontendUrl);
            if ($message === null || $urlParts === false || ! in_array($urlParts['scheme'] ?? '', ['http', 'https'], true)
                || empty($urlParts['host']) || isset($urlParts['user']) || isset($urlParts['pass']) || isset($urlParts['query']) || isset($urlParts['fragment'])) {
                $delivery->update(['status' => 'skipped']);

                return;
            }

            $sent = Mail::to($recipient->email)->send(new HrisUpdateMail(
                $message['title'], $message['message'], $frontendUrl.$message['path'], $frontendUrl,
            ));
            $delivery->update($sent instanceof SentMessage
                ? ['status' => 'sent', 'sent_at' => now()]
                : ['status' => 'skipped', 'sent_at' => null]);
        });
    }

    public function failed(string $deliveryId): void
    {
        EmailDelivery::query()->whereKey($deliveryId)->whereNotIn('status', ['sent', 'skipped'])->update(['status' => 'failed']);
    }
}
