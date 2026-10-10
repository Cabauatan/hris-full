<?php

namespace App\Services\Announcement;

use App\Models\Announcement;
use App\Models\User;
use App\Services\Notification\NotificationService;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AnnouncementService
{
    public function __construct(private readonly NotificationService $notifications) {}

    private const PRIORITIES = [
        'normal',
        'important',
        'urgent',
    ];

    private function ensureValidPriority(string $priority): void
    {
        if (! in_array($priority, self::PRIORITIES, true)) {
            throw new RuntimeException(
                "Invalid announcement priority: {$priority}."
            );
        }
    }

    private function ensureValidPublicationWindow(
        ?DateTimeInterface $publishedAt,
        ?DateTimeInterface $expiresAt
    ): void {
        if (
            $publishedAt !== null
            && $expiresAt !== null
            && $expiresAt <= $publishedAt
        ) {
            throw new RuntimeException(
                'Announcement expiration must be after publication.'
            );
        }
    }

    public function createDraft(
        User $createdBy,
        string $title,
        string $content,
        string $priority = 'normal',
        bool $showOnDashboard = true,
        ?string $attachmentPath = null
    ): Announcement {
        $this->ensureValidPriority($priority);

        return DB::transaction(function () use (
            $createdBy,
            $title,
            $content,
            $priority,
            $showOnDashboard,
            $attachmentPath
        ) {
            return Announcement::create([
                'title' => $title,
                'content' => $content,
                'priority' => $priority,
                'published_at' => null,
                'expires_at' => null,
                'show_on_dashboard' => $showOnDashboard,
                'attachment_path' => $attachmentPath,
                'is_active' => true,
                'created_by_user_id' => $createdBy->id,
            ])->fresh([
                'creator',
            ]);
        });
    }

    public function publish(
        Announcement $announcement,
        ?DateTimeInterface $expiresAt = null
    ): Announcement {
        return DB::transaction(function () use (
            $announcement,
            $expiresAt
        ) {
            $announcement = Announcement::query()
                ->lockForUpdate()
                ->findOrFail($announcement->id);

            if ($announcement->published_at !== null) {
                throw new RuntimeException(
                    'Announcement is already published or scheduled.'
                );
            }

            $publishedAt = now();

            $this->ensureValidPublicationWindow(
                $publishedAt,
                $expiresAt
            );

            $announcement->update([
                'published_at' => $publishedAt,
                'expires_at' => $expiresAt,
            ]);

            $this->notifications->queue($announcement, 'published');

            return $announcement->fresh([
                'creator',
            ]);
        });
    }

    public function schedule(
        Announcement $announcement,
        DateTimeInterface $scheduledAt,
        ?DateTimeInterface $expiresAt = null
    ): Announcement {
        return DB::transaction(function () use (
            $announcement,
            $scheduledAt,
            $expiresAt
        ) {
            $announcement = Announcement::query()
                ->lockForUpdate()
                ->findOrFail($announcement->id);

            if ($announcement->published_at !== null) {
                throw new RuntimeException(
                    'Announcement is already published or scheduled.'
                );
            }

            if ($scheduledAt <= now()) {
                throw new RuntimeException(
                    'Scheduled publication must be in the future.'
                );
            }

            $this->ensureValidPublicationWindow(
                $scheduledAt,
                $expiresAt
            );

            $announcement->update([
                'published_at' => $scheduledAt,
                'expires_at' => $expiresAt,
            ]);

            $this->notifications->queue($announcement, 'published');

            return $announcement->fresh([
                'creator',
            ]);
        });
    }

    public function update(
        Announcement $announcement,
        string $title,
        string $content,
        string $priority,
        bool $showOnDashboard,
        ?string $attachmentPath = null
    ): Announcement {
        $this->ensureValidPriority($priority);

        return DB::transaction(function () use (
            $announcement,
            $title,
            $content,
            $priority,
            $showOnDashboard,
            $attachmentPath
        ) {
            $announcement = Announcement::query()
                ->lockForUpdate()
                ->findOrFail($announcement->id);

            if (
                $announcement->published_at !== null
                && $announcement->published_at <= now()
            ) {
                throw new RuntimeException(
                    'Published announcements cannot be edited.'
                );
            }

            $announcement->update([
                'title' => $title,
                'content' => $content,
                'priority' => $priority,
                'show_on_dashboard' => $showOnDashboard,
                'attachment_path' => $attachmentPath,
            ]);

            return $announcement->fresh([
                'creator',
            ]);
        });
    }

    public function reschedule(
        Announcement $announcement,
        DateTimeInterface $scheduledAt,
        ?DateTimeInterface $expiresAt = null
    ): Announcement {
        return DB::transaction(function () use (
            $announcement,
            $scheduledAt,
            $expiresAt
        ) {
            $announcement = Announcement::query()
                ->lockForUpdate()
                ->findOrFail($announcement->id);

            if ($announcement->published_at === null) {
                throw new RuntimeException(
                    'Announcement is not scheduled.'
                );
            }

            if ($announcement->published_at <= now()) {
                throw new RuntimeException(
                    'Published announcements cannot be rescheduled.'
                );
            }

            if ($scheduledAt <= now()) {
                throw new RuntimeException(
                    'Scheduled publication must be in the future.'
                );
            }

            $this->ensureValidPublicationWindow(
                $scheduledAt,
                $expiresAt
            );

            $announcement->update([
                'published_at' => $scheduledAt,
                'expires_at' => $expiresAt,
            ]);

            $this->notifications->queue($announcement, 'published');

            return $announcement->fresh([
                'creator',
            ]);
        });
    }

    public function cancelSchedule(
        Announcement $announcement
    ): Announcement {
        return DB::transaction(function () use ($announcement) {
            $announcement = Announcement::query()
                ->lockForUpdate()
                ->findOrFail($announcement->id);

            if ($announcement->published_at === null) {
                throw new RuntimeException(
                    'Announcement is not scheduled.'
                );
            }

            if ($announcement->published_at <= now()) {
                throw new RuntimeException(
                    'Published announcements cannot have their schedule cancelled.'
                );
            }

            $announcement->update([
                'published_at' => null,
                'expires_at' => null,
            ]);

            return $announcement->fresh([
                'creator',
            ]);
        });
    }

    public function deactivate(
        Announcement $announcement
    ): Announcement {
        return DB::transaction(function () use ($announcement) {
            $announcement = Announcement::query()
                ->lockForUpdate()
                ->findOrFail($announcement->id);

            if (! $announcement->is_active) {
                throw new RuntimeException(
                    'Announcement is already inactive.'
                );
            }

            $announcement->update([
                'is_active' => false,
            ]);

            return $announcement->fresh([
                'creator',
            ]);
        });
    }

    public function reactivate(
        Announcement $announcement
    ): Announcement {
        return DB::transaction(function () use ($announcement) {
            $announcement = Announcement::query()
                ->lockForUpdate()
                ->findOrFail($announcement->id);

            if ($announcement->is_active) {
                throw new RuntimeException(
                    'Announcement is already active.'
                );
            }

            $announcement->update([
                'is_active' => true,
            ]);

            return $announcement->fresh([
                'creator',
            ]);
        });
    }

    public function getDashboardAnnouncements(
        ?DateTimeInterface $at = null
    ): Collection {
        $at ??= now();

        return Announcement::query()
            ->where('is_active', true)
            ->where('show_on_dashboard', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $at)
            ->where(function ($query) use ($at) {
                $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $at);
            })
            ->orderByRaw("
            CASE priority
                WHEN 'urgent' THEN 1
                WHEN 'important' THEN 2
                WHEN 'normal' THEN 3
                ELSE 4
            END
        ")
            ->orderByDesc('published_at')
            ->get();
    }
}
