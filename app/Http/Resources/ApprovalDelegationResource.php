<?php

namespace App\Http\Resources;

class ApprovalDelegationResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'delegator_user_id',
        'delegate_user_id',
        'starts_at',
        'ends_at',
        'reason',
        'is_active',
        'created_by_user_id',
        'created_at',
        'updated_at',
    ];
}
