<?php

namespace App\Models;

use Database\Factories\ApplicantExamAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicantExamAttempt extends Model
{
    /** @use HasFactory<ApplicantExamAttemptFactory> */
    use HasFactory;

    protected $fillable = [
        'job_application_id', 'recruitment_exam_id', 'assigned_by', 'questions',
        'answers', 'grades', 'status', 'automatic_score', 'score', 'max_score',
        'passing_percentage', 'percentage', 'passed', 'submitted_at',
        'graded_at', 'graded_by',
    ];

    protected $hidden = ['questions'];

    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'answers' => 'array',
            'grades' => 'array',
            'automatic_score' => 'decimal:2',
            'score' => 'decimal:2',
            'max_score' => 'integer',
            'passing_percentage' => 'decimal:2',
            'percentage' => 'decimal:2',
            'passed' => 'boolean',
            'submitted_at' => 'datetime',
            'graded_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(RecruitmentExam::class, 'recruitment_exam_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }
}
