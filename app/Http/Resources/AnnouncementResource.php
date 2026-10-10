<?php

namespace App\Http\Resources;

class AnnouncementResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'title',
        'content',
        'priority',
        'published_at',
        'expires_at',
        'show_on_dashboard',
        'is_active',
        'created_by_user_id',
        'created_at',
        'updated_at',
    ];
}
