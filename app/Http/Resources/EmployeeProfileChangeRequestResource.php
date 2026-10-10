<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EmployeeProfileChangeRequestResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'employee_id',
        'reason',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_remarks',
        'applied_at',
        'applied_by_user_id',
        'cancelled_at',
        'cancellation_reason',
        'created_by_user_id',
        'created_at',
        'updated_at',
    ];

    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'items' => EmployeeProfileChangeItemResource::collection($this->whenLoaded('items')),
            'abilities' => $this->when($request->routeIs('api.v1.employee-profile-change-requests.show'), fn (): array => [
                'approve' => $this->status === 'pending' && Gate::forUser($request->user())->allows('approve', $this->resource),
                'reject' => $this->status === 'pending' && Gate::forUser($request->user())->allows('reject', $this->resource),
                'cancel' => $this->status === 'pending' && Gate::forUser($request->user())->allows('cancel', $this->resource),
                'apply' => $this->status === 'approved' && Gate::forUser($request->user())->allows('apply', $this->resource),
            ]),
        ];
    }
}
