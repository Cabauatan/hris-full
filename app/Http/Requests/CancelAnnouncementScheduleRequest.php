<?php

namespace App\Http\Requests;

use App\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;

class CancelAnnouncementScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $announcement = $this->route('announcement');

        return $announcement instanceof Announcement
            && $this->user()?->can('update', $announcement) === true;
    }

    public function rules(): array
    {
        return [];
    }
}
