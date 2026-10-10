<?php

namespace App\Models;

use Database\Factories\JobApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobApplication extends Model
{
    /** @use HasFactory<JobApplicationFactory> */
    use HasFactory;

    protected $fillable = [
        'applicant_id', 'job_opening_id', 'desired_position', 'monthly_salary', 'cover_letter', 'status', 'remarks',
        'reviewed_by', 'reviewed_at', 'hired_by', 'hired_at', 'start_date',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'hired_at' => 'datetime',
            'start_date' => 'date',
            'monthly_salary' => 'decimal:2',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function jobOpening(): BelongsTo
    {
        return $this->belongsTo(JobOpening::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ApplicantExamAttempt::class);
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(RecruitmentInterview::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function hirer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hired_by');
    }
}
