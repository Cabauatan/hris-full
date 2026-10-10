<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class RecruitmentExamResource extends ApiResource
{
    protected const FIELDS = ['id', 'title', 'instructions', 'max_score', 'is_active', 'created_at', 'updated_at'];

    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'passing_percentage' => (float) $this->passing_percentage,
            'questions' => $this->questions,
        ];
    }
}
