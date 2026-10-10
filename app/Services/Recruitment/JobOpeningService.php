<?php

namespace App\Services\Recruitment;

use App\Models\JobOpening;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class JobOpeningService
{
    /** @param array<string, mixed> $input */
    public function create(User $creator, array $input): JobOpening
    {
        return JobOpening::query()->create($this->attributes($input) + ['created_by' => $creator->id]);
    }

    /** @param array<string, mixed> $input */
    public function update(JobOpening $opening, array $input): JobOpening
    {
        return DB::transaction(function () use ($opening, $input): JobOpening {
            $opening = JobOpening::query()->lockForUpdate()->findOrFail($opening->id);
            $opening->update($this->attributes($input));

            return $opening;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function attributes(array $input): array
    {
        $attributes = [
            'title' => trim($input['title']),
            'description' => $input['description'],
            'requirements' => $input['requirements'] ?? null,
            'location' => $input['location'] ?? null,
            'employment_type' => $input['employment_type'] ?? null,
            'vacancies' => $input['vacancies'] ?? 1,
            'status' => $input['status'] ?? 'open',
            'closes_on' => $input['closes_on'] ?? null,
            'monthly_salary' => $input['monthly_salary'] ?? null,
        ];

        Validator::make($attributes, [
            'status' => ['required', Rule::in(['open', 'closed'])],
            'closes_on' => ['nullable', 'date_format:Y-m-d'],
            'monthly_salary' => ['required', 'numeric', 'regex:/^\d+(?:\.\d{1,2})?$/', 'min:0.01', 'max:9999999999999.99'],
        ])->validate();

        if ($attributes['status'] === 'open' && $attributes['closes_on'] !== null
            && $attributes['closes_on'] < now()->toDateString()) {
            throw ValidationException::withMessages(['closes_on' => 'An open position must close today or later.']);
        }

        return $attributes;
    }
}
