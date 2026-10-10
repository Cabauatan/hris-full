<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyProfile extends Model
{
    protected $fillable = [
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
        'logo_path',
    ];
}
