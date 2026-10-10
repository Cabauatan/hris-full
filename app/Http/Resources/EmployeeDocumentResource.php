<?php

namespace App\Http\Resources;

class EmployeeDocumentResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'employee_id',
        'document_type',
        'title',
        'original_filename',
        'mime_type',
        'file_size',
        'issued_at',
        'expires_at',
        'remarks',
        'uploaded_by_user_id',
        'created_at',
        'updated_at',
    ];

    protected const DATES = ['expires_at', 'issued_at'];
}
