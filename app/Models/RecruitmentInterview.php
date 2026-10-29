<?php

namespace App\Models;

use Database\Factories\RecruitmentInterviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecruitmentInterview extends Model
{
    /** @use HasFactory<RecruitmentInterviewFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'job_application_id',
        'round',
        'status',
        'scheduled_by',
        'scheduled_at',
        'duration_minutes',
        'started_at',
        'ended_at',
        'completed_by',
        'private_notes',
    ];

    protected $hidden = ['private_notes'];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'duration_minutes' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function scheduler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function interviewers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(RecruitmentInterviewMessage::class);
    }
}
