<?php

namespace App\Services\Notification;

use App\Jobs\DeliverHrisNotification;
use App\Models\Announcement;
use App\Models\AttendanceCorrectionRequest;
use App\Models\CtoRequest;
use App\Models\EmployeeProfileChangeRequest;
use App\Models\LeaveRequest;
use App\Models\OfficialBusinessRequest;
use App\Models\OvertimeRequest;
use App\Models\Payslip;
use App\Models\TravelOrderRequest;
use App\Models\User;
use App\Notifications\HrisNotification;
use App\Services\Approval\ApprovalAuthorityResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

class NotificationService
{
    /** @var array<string, Model|null> */
    private array $presentationRecords = [];

    /** @var array<string, bool> */
    private array $presentationAccess = [];

    private ?int $presentationUserId = null;

    private ?int $presentationEmployeeId = null;

    /** @var array<string, array{class: class-string<Model>, module: string, label: string, category: string}> */
    public const RESOURCES = [
        'leave' => ['class' => LeaveRequest::class, 'module' => 'leave', 'label' => 'Leave request', 'category' => 'request'],
        'cto' => ['class' => CtoRequest::class, 'module' => 'cto', 'label' => 'CTO request', 'category' => 'request'],
        'overtime' => ['class' => OvertimeRequest::class, 'module' => 'overtime', 'label' => 'Overtime request', 'category' => 'request'],
        'official_business' => ['class' => OfficialBusinessRequest::class, 'module' => 'official-business', 'label' => 'Official business request', 'category' => 'request'],
        'travel_order' => ['class' => TravelOrderRequest::class, 'module' => 'travel-orders', 'label' => 'Travel order request', 'category' => 'request'],
        'attendance_correction' => ['class' => AttendanceCorrectionRequest::class, 'module' => 'attendance-corrections', 'label' => 'Attendance correction', 'category' => 'request'],
        'profile_changes' => ['class' => EmployeeProfileChangeRequest::class, 'module' => 'profile-changes', 'label' => 'Profile change request', 'category' => 'request'],
        'announcement' => ['class' => Announcement::class, 'module' => 'announcements', 'label' => 'Announcement', 'category' => 'announcement'],
        'payslip' => ['class' => Payslip::class, 'module' => 'payslips', 'label' => 'Payslip', 'category' => 'payslip'],
    ];

    public function __construct(
        private readonly ApprovalAuthorityResolver $authority,
        private readonly OutboundNotificationService $updates,
    ) {}

    public function queue(Model $record, string $event): void
    {
        $type = collect(self::RESOURCES)->search(fn (array $definition): bool => $record instanceof $definition['class']);
        if ($type === false || ! in_array($event, ['pending', 'approved', 'rejected', 'cancelled', 'applied', 'published'], true)) {
            throw new InvalidArgumentException('Unsupported HRIS notification transition.');
        }
        $version = $type === 'announcement' ? $record->published_at?->format('Y-m-d H:i:s') : null;
        $eventId = $type === 'announcement' ? Uuid::uuid5(Uuid::NAMESPACE_URL, 'hris/announcement/'.$record->getKey().'/'.$version)->toString() : (string) Str::uuid();
        $occurredAt = $type === 'announcement' && $record->published_at !== null ? $record->published_at->toIso8601String() : now()->toIso8601String();
        $job = (new DeliverHrisNotification($type, (int) $record->getKey(), $event, $eventId, $occurredAt, $version))
            ->onQueue('notifications')->afterCommit();
        if ($type === 'announcement' && $record->published_at?->isFuture()) {
            $job->delay($record->published_at);
        }
        dispatch($job);
    }

    public function deliver(DeliverHrisNotification $job): void
    {
        $definition = self::RESOURCES[$job->resourceType] ?? null;
        $record = $definition === null ? null : $definition['class']::query()->find($job->resourceId);
        if ($record === null || ($job->event === 'pending' && $record->status !== 'pending')) {
            return;
        }
        if ($record instanceof Announcement && ($record->published_at?->format('Y-m-d H:i:s') !== $job->publicationVersion || ! $this->isPublishedAnnouncement($record))) {
            return;
        }
        if ($record instanceof Payslip && $record->published_at === null) {
            return;
        }
        if ($record instanceof Announcement || ($job->resourceType === 'profile_changes' && $job->event === 'pending')) {
            if ($job->recipientIds !== null) {
                foreach (User::query()->where('is_active', true)->whereIn('id', $job->recipientIds)->get() as $user) {
                    $this->send($user, $record, $job);
                }

                return;
            }
            User::query()->where('is_active', true)->chunkById(100, function ($users) use ($job): void {
                dispatch((new DeliverHrisNotification($job->resourceType, $job->resourceId, $job->event, $job->eventId, $job->occurredAt, $job->publicationVersion, $users->modelKeys()))->onQueue('notifications')->afterCommit());
            });

            return;
        }
        if ($job->event === 'pending') {
            $employee = $record->employee;
            $reviewer = $employee === null ? null : $this->authority->resolve($employee, $job->resourceType);
            if ($reviewer !== null) {
                $this->send($reviewer, $record, $job);
            }

            return;
        }
        $employeeUserId = $record->employee()->value('user_id');
        $creatorId = $record instanceof Payslip ? null : $record->created_by_user_id;
        foreach (User::query()->where('is_active', true)->whereIn('id', array_filter([$employeeUserId, $creatorId]))->get() as $user) {
            $this->send($user, $record, $job);
        }
    }

    private function send(User $user, Model $record, DeliverHrisNotification $job): void
    {
        if (! $user->is_active || ! $user->can('view', $record) || ($job->event === 'pending' && ! $user->can('approve', $record))) {
            return;
        }
        $notification = new HrisNotification([
            'resource_type' => $job->resourceType,
            'resource_id' => $job->resourceId,
            'event' => $job->event,
            'occurred_at' => $job->occurredAt,
        ]);
        $notification->id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'hris/'.$job->eventId.'/'.$user->id)->toString();
        $user->notifyNow($notification);
        $this->updates->queue($job->resourceType, $job->resourceId, $job->event, $user, $job->eventId, $job->publicationVersion);
    }

    public function isPublishedAnnouncement(Announcement $announcement): bool
    {
        return $announcement->is_active && $announcement->published_at !== null && $announcement->published_at <= now()
            && ($announcement->expires_at === null || $announcement->expires_at > now());
    }

    /** @param iterable<DatabaseNotification> $notifications */
    public function preparePresentations(User $user, iterable $notifications): void
    {
        $this->presentationRecords = [];
        $this->presentationAccess = [];
        $this->presentationUserId = $user->id;
        $this->presentationEmployeeId = $user->employee()->value('id');
        $ids = [];
        foreach ($notifications as $notification) {
            $data = is_array($notification->data) ? $notification->data : [];
            $type = is_string($data['resource_type'] ?? null) ? $data['resource_type'] : '';
            $id = filter_var($data['resource_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($notification->type === HrisNotification::class && isset(self::RESOURCES[$type]) && $id !== false) {
                $ids[$type][] = $id;
                $this->presentationRecords[$type.':'.$id] = null;
            }
        }
        foreach ($ids as $type => $resourceIds) {
            $class = self::RESOURCES[$type]['class'];
            $query = $class::query()->whereKey(array_unique($resourceIds));
            if (! in_array($type, ['announcement', 'payslip', 'profile_changes'], true)) {
                $query->with('employee.supervisor.user');
            }
            foreach ($query->get() as $record) {
                $this->presentationRecords[$type.':'.$record->getKey()] = $record;
            }
        }
    }

    /** @return array<string, mixed> */
    public function present(User $user, DatabaseNotification $notification): array
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $type = is_string($data['resource_type'] ?? null) ? $data['resource_type'] : '';
        $definition = self::RESOURCES[$type] ?? null;
        $event = is_string($data['event'] ?? null) && in_array($data['event'], ['pending', 'approved', 'rejected', 'cancelled', 'applied', 'published'], true) ? $data['event'] : null;
        $id = filter_var($data['resource_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $source = null;
        if ($notification->type === HrisNotification::class && $definition !== null && $event !== null && $id !== false) {
            $prepared = $this->presentationUserId === $user->id;
            $record = $prepared && array_key_exists($type.':'.$id, $this->presentationRecords) ? $this->presentationRecords[$type.':'.$id] : $definition['class']::query()->find($id);
            $canView = false;
            if ($record !== null) {
                $accessKey = $type.':'.($record instanceof Announcement ? $id : $record->employee_id).':'.($record instanceof Payslip && $record->published_at !== null ? 'published' : '');
                $canView = $prepared ? ($this->presentationAccess[$accessKey] ??= $user->can('view', $record)) : $user->can('view', $record);
            }
            if ($record !== null && $canView && (! ($record instanceof Announcement) || $this->isPublishedAnnouncement($record))) {
                $employeeId = $prepared ? $this->presentationEmployeeId : $user->employee()->value('id');
                $own = ! ($record instanceof Announcement) && $record->employee_id === $employeeId;
                $source = ['module' => $definition['module'], 'id' => $id, 'scope' => $own ? 'mine' : 'visible'];
            }
        }

        return [
            'id' => $notification->id,
            'title' => $source === null ? 'HRIS update' : $definition['label'].' '.($event === 'pending' ? 'awaiting review' : $event),
            'message' => $source === null ? 'This record is no longer available to your account.' : match ($event) {
                'pending' => 'A request is waiting for your review.',
                'published' => $type === 'payslip' ? 'Your published payslip is ready to view.' : 'A published announcement is available.',
                'applied' => 'The approved profile changes have been applied.',
                default => 'The request has been '.$event.'.',
            },
            'category' => $definition['category'] ?? 'request',
            'event' => $event,
            'created_at' => $notification->created_at?->toIso8601String(),
            'read_at' => $notification->read_at?->toIso8601String(),
            'source' => $source,
        ];
    }
}
