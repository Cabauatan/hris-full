<?php

namespace App\Http\Resources;

use App\Models\Applicant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ApplicantExamAttemptResource extends ApiResource
{
    protected const FIELDS = [
        'id', 'job_application_id', 'recruitment_exam_id', 'status', 'max_score',
        'submitted_at', 'graded_at', 'created_at', 'updated_at',
    ];

    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $isStaff = $actor instanceof User;
        $isGraded = $this->status === 'graded';
        $application = $this->resource->getRelation('application');
        $exam = $this->resource->getRelation('exam');
        $questions = array_map(static fn (array $question): array => Arr::only(
            $question,
            $isStaff ? ['id', 'type', 'prompt', 'points', 'options', 'correct_option'] : ['id', 'type', 'prompt', 'points', 'options'],
        ), $this->questions);

        return parent::toArray($request) + [
            'title' => $exam->title,
            'instructions' => $exam->instructions,
            'passing_percentage' => (float) $this->passing_percentage,
            'questions' => $questions,
            'answers' => $this->answers,
            'grades' => $isGraded || $isStaff ? $this->grades : null,
            'score' => $isGraded ? (float) $this->score : null,
            'percentage' => $isGraded ? (float) $this->percentage : null,
            'passed' => $isGraded ? $this->passed : null,
            'automatic_score' => $this->when($isStaff, fn (): float => (float) $this->automatic_score),
            'application' => [
                'id' => $application->id, 'status' => $application->status,
                'desired_position' => $application->desired_position,
                'applicant' => new ApplicantResource($application->applicant),
            ],
            'abilities' => [
                'submit' => $actor instanceof Applicant && $actor->is_active
                    && $actor->id === $application->applicant_id && $application->status === 'approved' && $this->status === 'assigned',
                'grade' => $isStaff && $application->status === 'approved'
                    && $this->status === 'submitted' && $actor->hasPermission('recruitment.grade'),
            ],
        ];
    }
}
