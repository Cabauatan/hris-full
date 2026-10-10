<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\Recruitment\RecruitmentInterviewService;
use DateTimeInterface;
use Illuminate\Http\Request;

class RecruitmentInterviewResource extends ApiResource
{
    protected const FIELDS = [
        'id', 'uuid', 'job_application_id', 'round', 'status', 'scheduled_at',
        'duration_minutes', 'started_at', 'ended_at', 'created_at', 'updated_at',
    ];

    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $window = app(RecruitmentInterviewService::class)->window($this->resource);
        $canManage = $actor instanceof User && $actor->hasPermission('recruitment.manage');
        $scheduled = $this->status === 'scheduled' && $this->application->status === 'approved';

        return parent::toArray($request) + [
            'scheduled_by' => $this->whenLoaded('scheduler', fn (): ?array => $this->scheduler === null ? null : ['id' => $this->scheduler->id, 'name' => $this->scheduler->name]),
            'interviewers' => $this->whenLoaded('interviewers', fn (): array => $this->interviewers->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->all()),
            'join_available_from' => $window['from']->format(DateTimeInterface::ATOM),
            'join_available_until' => $window['until']->format(DateTimeInterface::ATOM),
            'private_notes' => $this->when($actor instanceof User && ($canManage || $actor->hasPermission('recruitment.view')), $this->private_notes),
            'abilities' => [
                'view' => $actor?->can('view', $this->resource) ?? false,
                'join' => $actor?->can('join', $this->resource) ?? false,
                'cancel' => $canManage && $scheduled,
                'complete' => $canManage && $scheduled,
            ],
        ];
    }
}
