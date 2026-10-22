<?php

namespace App\Services\Notification;

use App\Jobs\DeliverBirthdayNotification;
use App\Models\Employee;
use App\Notifications\HrisNotification;
use App\Services\Workspace\BirthdayScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Ramsey\Uuid\Uuid;

class BirthdayNotificationService
{
    public function __construct(
        private readonly BirthdayScope $birthdays,
        private readonly OutboundNotificationService $updates,
    ) {}

    public function queueDue(): int
    {
        $today = CarbonImmutable::now(config('app.timezone'));

        if ($today->hour < 8) {
            return 0;
        }

        $queued = 0;
        Employee::query()->select('id')->where('is_active', true)->whereYear('birth_date', '<', $today->year)
            ->where(function (Builder $employees) use ($today): void {
                $employees->where(fn (Builder $date): Builder => $date->whereMonth('birth_date', $today->month)->whereDay('birth_date', $today->day));

                if ($today->month === 2 && $today->day === 28 && ! $today->isLeapYear()) {
                    $employees->orWhere(fn (Builder $date): Builder => $date->whereMonth('birth_date', 2)->whereDay('birth_date', 29));
                }
            })
            ->whereHas('position', fn (Builder $positions): Builder => $positions->where('is_active', true)
                ->whereHas('department', fn (Builder $departments): Builder => $departments->where('is_active', true)))
            ->chunkById(100, function ($employees) use ($today, &$queued): void {
                foreach ($employees as $employee) {
                    dispatch((new DeliverBirthdayNotification($employee->id, $today->toDateString()))->onQueue('notifications')->afterCommit());
                    $queued++;
                }
            });

        return $queued;
    }

    public function deliver(DeliverBirthdayNotification $job): void
    {
        $today = CarbonImmutable::now(config('app.timezone'));

        if ($today->hour < 8 || $job->birthdayDate !== $today->toDateString()) {
            return;
        }

        $employee = Employee::query()->with('position.department.division')->find($job->employeeId);

        if ($employee === null || ! $employee->is_active || ! $this->birthdays->matchesDate($employee, $today)) {
            return;
        }

        $recipients = $this->birthdays->coworkers($employee)->whereNotNull('user_id')
            ->when($employee->user_id !== null, fn (Builder $coworkers): Builder => $coworkers->where('user_id', '!=', $employee->user_id))
            ->whereHas('user', fn (Builder $users): Builder => $users->where('is_active', true));

        if ($job->recipientIds === null) {
            $recipients->select('id')->chunkById(100, function ($coworkers) use ($job): void {
                dispatch((new DeliverBirthdayNotification($job->employeeId, $job->birthdayDate, $coworkers->modelKeys()))->onQueue('notifications')->afterCommit());
            });

            return;
        }

        $eventId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'hris/birthday/'.$employee->id.'/'.$job->birthdayDate)->toString();
        $occurredAt = $today->setTime(8, 0)->toIso8601String();

        foreach ($recipients->whereKey($job->recipientIds)->with('user')->get() as $coworker) {
            $recipient = $coworker->user;
            $notification = new HrisNotification([
                'resource_type' => 'birthday', 'resource_id' => $employee->id,
                'event' => 'birthday', 'occurred_at' => $occurredAt,
            ]);
            $notification->id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'hris/'.$eventId.'/'.$recipient->id)->toString();
            $recipient->notifyNow($notification);
            $this->updates->queue('birthday', $employee->id, 'birthday', $recipient, $eventId, $job->birthdayDate);
        }
    }
}
