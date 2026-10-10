<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ApplicantResource extends ApiResource
{
    protected const FIELDS = ['id', 'uuid', 'first_name', 'middle_name', 'last_name', 'email', 'mobile_number', 'is_active', 'last_login_at'];

    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'name' => implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name], fn (?string $part): bool => $part !== null && $part !== '')),
        ];
    }
}
