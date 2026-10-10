<?php

namespace App\Http\Requests;

class GetDashboardRequest extends WorkspaceDateRangeRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewDashboard') === true && $this->canUseScope();
    }
}
