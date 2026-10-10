<?php

namespace App\Http\Requests;

use App\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(
            'create',
            Announcement::class
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
                'sometimes',
                'string',
                Rule::in([
                    'normal',
                    'important',
                    'urgent',
                ]),
            ],

            'show_on_dashboard' => [
                'sometimes',
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
