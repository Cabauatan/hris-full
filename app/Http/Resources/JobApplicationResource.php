<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;

class JobApplicationResource extends ApiResource
{
    protected const FIELDS = [
        'id', 'applicant_id', 'job_opening_id', 'desired_position', 'monthly_salary', 'cover_letter', 'status', 'remarks',
        'reviewed_at', 'hired_at', 'start_date', 'created_at', 'updated_at',
    ];

    protected const DATES = ['start_date'];

    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $canManage = $actor instanceof User && $actor->hasPermission('recruitment.manage');
        $ungraded = $this->resource->getAttribute('ungraded_attempts_count');
        $unfinishedInterviews = $this->resource->getAttribute('unfinished_interviews_count');

        return parent::toArray($request) + [
            'salary_currency' => 'PHP',
            'applicant' => new ApplicantResource($this->whenLoaded('applicant')),
            'attempts' => ApplicantExamAttemptResource::collection($this->whenLoaded('attempts')),
            'abilities' => [
                'approve' => $canManage && $this->status === 'pending',
                'reject' => $canManage && in_array($this->status, ['pending', 'approved'], true),
                'assign_exam' => $canManage && $this->status === 'approved',
                'schedule_interview' => $canManage && $this->status === 'approved',
                'hire' => $canManage && $this->status === 'approved'
                    && $ungraded !== null && (int) $ungraded === 0
                    && $unfinishedInterviews !== null && (int) $unfinishedInterviews === 0,
            ],
        ];
    }
}
