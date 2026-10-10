<?php

namespace App\Models;

use Database\Factories\JobOpeningFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobOpening extends Model
{
    /** @use HasFactory<JobOpeningFactory> */
    use HasFactory;

    protected $fillable = [
        'title', 'description', 'requirements', 'location', 'employment_type',
        'vacancies', 'status', 'closes_on', 'monthly_salary', 'created_by',
    ];

    protected function casts(): array
    {
        return ['vacancies' => 'integer', 'closes_on' => 'date', 'monthly_salary' => 'decimal:2'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    public function acceptingApplications(): bool
    {
        return $this->status === 'open'
            && $this->monthly_salary !== null && $this->monthly_salary > 0
            && ($this->closes_on === null || $this->closes_on->toDateString() >= now()->toDateString());
    }

    #[Scope]
    protected function accepting(Builder $query): Builder
    {
        return $query->where('status', 'open')->whereNotNull('monthly_salary')->where('monthly_salary', '>', 0)->where(function (Builder $dates): void {
            $dates->whereNull('closes_on')->orWhereDate('closes_on', '>=', now()->toDateString());
        });
    }
}
