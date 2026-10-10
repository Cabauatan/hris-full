<?php

namespace App\Http\Requests;

use App\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAnnouncementRequest extends FormRequest
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
            'title' => [
                'required',
                'string',
                'max:200',
            ],

            'content' => [
                'required',
                'string',
            ],

            'priority' => [
                'required',
                'string',
                Rule::in([
                    'normal',
                    'important',
                    'urgent',
                ]),
            ],

            'show_on_dashboard' => [
                'required',
                'boolean',
            ],

            'attachment_path' => [
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }
}
