<?php

namespace App\Services\Notification;

use App\Models\Announcement;
use App\Models\Applicant;
use App\Models\ApplicantExamAttempt;
use App\Models\JobApplication;
use App\Models\Payslip;
use App\Models\RecruitmentInterview;
use App\Models\User;
use App\Services\Approval\ApprovalAuthorityResolver;
use App\Services\Workspace\BirthdayScope;
use Carbon\CarbonImmutable;

class HrisMessageService
{
    /** @var array<string, list<string>> */
    private const EVENTS = [
        'leave' => ['pending', 'approved', 'rejected', 'cancelled'],
        'cto' => ['pending', 'approved', 'rejected', 'cancelled'],
        'overtime' => ['pending', 'approved', 'rejected', 'cancelled'],
        'official_business' => ['pending', 'approved', 'rejected', 'cancelled'],
        'travel_order' => ['pending', 'approved', 'rejected', 'cancelled'],
        'attendance_correction' => ['pending', 'approved', 'rejected', 'cancelled'],
        'profile_changes' => ['pending', 'approved', 'rejected', 'cancelled', 'applied'],
        'announcement' => ['published'],
        'payslip' => ['published'],
        'applicant' => ['registered'],
        'job_application' => ['submitted', 'approved', 'rejected', 'hired'],
        'applicant_exam_attempt' => ['assigned', 'result_ready'],
        'recruitment_interview' => ['scheduled', 'cancelled'],
        'user' => ['password_changed'],
        'birthday' => ['birthday'],
    ];

    public function __construct(
        private readonly ApprovalAuthorityResolver $authority,
        private readonly BirthdayScope $birthdays,
    ) {}

    public function supports(string $type, string $event): bool
    {
        return in_array($event, self::EVENTS[$type] ?? [], true);
    }

    /** @return array{title: string, message: string, path: string}|null */
    public function resolve(string $resourceType, int $resourceId, string $event, User|Applicant $recipient, ?string $sourceVersion = null): ?array
    {
        $recipient = $recipient::query()->find($recipient->getKey());
        if ($recipient === null || ! $recipient->is_active || ! $this->supports($resourceType, $event)) {
            return null;
        }
        if ($resourceType === 'birthday') {
            $today = CarbonImmutable::now(config('app.timezone'));

            if (! $recipient instanceof User || $today->hour < 8 || $sourceVersion !== $today->toDateString()) {
                return null;
            }

            $employee = $recipient->employee()->first();
            $celebrant = $employee === null ? null : $this->birthdays->coworkers($employee)->find($resourceId);

            return $celebrant !== null && $this->birthdays->matchesDate($celebrant, $today)
                ? $this->birthdays->message($celebrant, $today) : null;
        }
        if (isset(NotificationService::RESOURCES[$resourceType])) {
            return $recipient instanceof User ? $this->employeeMessage($resourceType, $resourceId, $event, $sourceVersion, $recipient) : null;
        }
        if ($resourceType === 'user') {
            return $recipient instanceof User && $recipient->id === $resourceId
                ? ['title' => 'Password changed', 'message' => 'Your HRIS password was changed. If you did not make this change, contact your HR administrator.', 'path' => '/profile'] : null;
        }
        if ($resourceType === 'applicant') {
            return $recipient instanceof Applicant && $recipient->id === $resourceId
                ? ['title' => 'Applicant registration complete', 'message' => 'Your HRIS applicant account is ready. Sign in to browse openings and manage your applications.', 'path' => '/applicant/applications'] : null;
        }
        if ($resourceType === 'job_application') {
            return $this->applicationMessage($resourceType, $resourceId, $event, $sourceVersion, $recipient);
        }
        if ($resourceType === 'applicant_exam_attempt') {
            return $recipient instanceof Applicant ? $this->examMessage($resourceType, $resourceId, $event, $sourceVersion, $recipient) : null;
        }

        return $this->interviewMessage($resourceType, $resourceId, $event, $sourceVersion, $recipient);
    }

    /** @return array{title: string, message: string, path: string}|null */
    private function employeeMessage(string $resourceType, int $resourceId, string $event, ?string $sourceVersion, User $recipient): ?array
    {
        $definition = NotificationService::RESOURCES[$resourceType];
        $record = $definition['class']::query()->find($resourceId);
        if ($record === null || ! $recipient->can('view', $record)) {
            return null;
        }
        if ($record instanceof Announcement) {
            if ($sourceVersion !== $record->published_at?->format('Y-m-d H:i:s')
                || ! $record->is_active || $record->published_at === null || $record->published_at->isFuture()
                || ($record->expires_at !== null && $record->expires_at <= now())) {
                return null;
            }

            return ['title' => 'Announcement published', 'message' => 'A published HRIS announcement is available to view.', 'path' => '/manage/announcements?record='.$record->id];
        }

        $ownerId = $record->employee()->value('user_id');
        if ($record instanceof Payslip) {
            return $record->published_at !== null && $ownerId === $recipient->id
                ? ['title' => 'Payslip published', 'message' => 'Your published payslip is ready. Sign in to HRIS to view it securely.', 'path' => '/my/payslips?record='.$record->id] : null;
        }
        if ($record->status !== $event) {
            return null;
        }
        if ($event === 'pending') {
            if (! $recipient->can('approve', $record) || ($resourceType !== 'profile_changes'
                && ($record->employee === null || $this->authority->resolve($record->employee, $resourceType)?->id !== $recipient->id))) {
                return null;
            }
        } elseif ($ownerId !== $recipient->id && $record->created_by_user_id !== $recipient->id) {
            return null;
        }

        return [
            'title' => $definition['label'].' '.($event === 'pending' ? 'awaiting review' : $event),
            'message' => match ($event) {
                'pending' => 'A request is waiting for your review in HRIS.',
                'applied' => 'Your approved profile changes have been applied. Sign in to view the update.',
                default => 'A request has been '.$event.'. Sign in to HRIS to view the details.',
            },
            'path' => ($ownerId === $recipient->id ? '/my/requests/' : '/requests/').$definition['module'].'?record='.$record->getKey(),
        ];
    }

    /** @return array{title: string, message: string, path: string}|null */
    private function applicationMessage(string $resourceType, int $resourceId, string $event, ?string $sourceVersion, User|Applicant $recipient): ?array
    {
        $application = JobApplication::query()->find($resourceId);
        $status = $event === 'submitted' ? 'pending' : $event;
        if ($application === null || $application->status !== $status) {
            return null;
        }
        $staff = $recipient instanceof User;
        if (($staff && ($event !== 'submitted' || ! $this->recruitmentManager($recipient)))
            || (! $staff && $application->applicant_id !== $recipient->id)) {
            return null;
        }

        return [
            'title' => 'Job application '.$event,
            'message' => match ($event) {
                'submitted' => $staff ? 'A job application is waiting for review in HRIS.' : 'Your job application has been submitted. Sign in to track its progress.',
                'hired' => 'You have been marked as hired. Sign in to view your start date and hiring details.',
                default => 'Your job application has been '.$event.'. Sign in to view the update.',
            },
            'path' => $staff ? '/recruitment' : '/applicant/applications',
        ];
    }

    /** @return array{title: string, message: string, path: string}|null */
    private function examMessage(string $resourceType, int $resourceId, string $event, ?string $sourceVersion, Applicant $recipient): ?array
    {
        $attempt = ApplicantExamAttempt::query()->with('application')->find($resourceId);
        if ($attempt === null || $attempt->application?->applicant_id !== $recipient->id) {
            return null;
        }
        if ($event === 'assigned') {
            if ($attempt->status !== 'assigned' || $attempt->application->status !== 'approved') {
                return null;
            }
        } elseif ($attempt->status !== 'graded' || $attempt->graded_at === null || $attempt->score === null
            || $attempt->percentage === null || $attempt->passed === null
            || ! in_array($attempt->application->status, ['approved', 'hired'], true)) {
            return null;
        }

        return [
            'title' => $event === 'assigned' ? 'Recruitment exam assigned' : 'Recruitment exam result ready',
            'message' => $event === 'assigned' ? 'An exam has been assigned to your application. Sign in to view the instructions.' : 'Your completed exam result is ready. Sign in to view it securely.',
            'path' => '/applicant/exams/'.$attempt->id,
        ];
    }

    /** @return array{title: string, message: string, path: string}|null */
    private function interviewMessage(string $resourceType, int $resourceId, string $event, ?string $sourceVersion, User|Applicant $recipient): ?array
    {
        $interview = RecruitmentInterview::query()->with('application')->find($resourceId);
        if ($interview === null || $interview->status !== $event
            || ($sourceVersion !== null && $sourceVersion !== $interview->scheduled_at?->format('Y-m-d H:i:s'))) {
            return null;
        }
        if ($event === 'scheduled') {
            if ($interview->application?->status !== 'approved' || $interview->scheduled_at === null
                || now()->gt($interview->scheduled_at->copy()->addMinutes($interview->duration_minutes + (int) config('recruitment.grace_minutes', 30)))) {
                return null;
            }
        } elseif (! in_array($interview->application?->status, ['approved', 'rejected', 'hired'], true)) {
            return null;
        }
        $staff = $recipient instanceof User;
        if (($staff && (! $this->recruitmentManager($recipient) || ! $interview->interviewers()->whereKey($recipient->id)->exists()))
            || (! $staff && $interview->application?->applicant_id !== $recipient->id)) {
            return null;
        }

        return [
            'title' => 'Recruitment interview '.$event,
            'message' => $event === 'scheduled' ? 'A recruitment interview has been scheduled. Sign in to view the schedule and interview room.' : 'A recruitment interview has been cancelled. Sign in to view the application update.',
            'path' => ($staff ? '/recruitment/interviews/' : '/applicant/interviews/').$interview->id,
        ];
    }

    private function recruitmentManager(User $user): bool
    {
        return $user->hasPermission('recruitment.manage') && $user->hasPermission('recruitment.view');
    }
}
