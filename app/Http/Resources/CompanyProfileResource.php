<?php

namespace App\Http\Resources;

class CompanyProfileResource extends ApiResource
{
    protected const FIELDS = [
        'id',
        'name',
        'legal_name',
        'registration_number',
        'tax_identification_number',
        'email',
        'phone',
        'website',
        'address_line_1',
        'address_line_2',
        'city',
        'province',
        'postal_code',
        'country',
        'created_at',
        'updated_at',
    ];
}
