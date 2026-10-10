<?php

namespace App\Models;

use Database\Factories\RecruitmentExamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecruitmentExam extends Model
{
    /** @use HasFactory<RecruitmentExamFactory> */
    use HasFactory;

    protected $fillable = [
        'title', 'instructions', 'questions', 'max_score',
        'passing_percentage', 'is_active', 'created_by',
    ];

    protected $hidden = ['questions'];

    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'max_score' => 'integer',
            'passing_percentage' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ApplicantExamAttempt::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
