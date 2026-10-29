<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecruitmentInterviewMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->toChatArray($this->interview->uuid);
    }
}
