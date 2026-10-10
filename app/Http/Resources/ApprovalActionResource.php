<?php

namespace App\Http\Resources;

class ApprovalActionResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'approvable_type',
        'approvable_id',
        'acted_by_user_id',
        'action',
        'remarks',
        'acted_at',
        'created_at',
        'updated_at',
    ];
}
