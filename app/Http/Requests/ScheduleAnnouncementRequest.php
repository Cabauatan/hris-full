<?php

namespace App\Http\Requests;

use App\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScheduleAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $announcement = $this->route('announcement');

        return $announcement instanceof Announcement
            && $this->user()?->can(
                'update',
                $announcement
            ) === true;
    }

    public function rules(): array
    {
        return [
            'scheduled_at' => [
                'required',
                'date',
                'after:now',
            ],

            'expires_at' => [
                'nullable',
                'date',
                Rule::when(is_string($this->input('scheduled_at')), 'after:scheduled_at'),
            ],
        ];
    }
}
