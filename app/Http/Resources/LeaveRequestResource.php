<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LeaveRequestResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'employee_id',
        'leave_type_id',
        'start_date',
        'end_date',
        'day_portion',
        'requested_units',
        'approved_units',
        'reason',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_remarks',
        'cancelled_at',
        'cancellation_reason',
        'created_by_user_id',
        'created_at',
        'updated_at',
    ];

    protected const DATES = ['start_date', 'end_date'];

    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'approval_actions' => ApprovalActionResource::collection($this->whenLoaded('approvalActions')),
            'abilities' => $this->when($request->routeIs('api.v1.leave-requests.show'), fn (): array => [
                'approve' => $this->status === 'pending' && Gate::forUser($request->user())->allows('approve', $this->resource),
                'reject' => $this->status === 'pending' && Gate::forUser($request->user())->allows('reject', $this->resource),
                'cancel' => $this->status === 'pending' && Gate::forUser($request->user())->allows('cancel', $this->resource),
            ]),
        ];
    }
}
