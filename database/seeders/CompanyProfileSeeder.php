<?php

namespace Database\Seeders;

use App\Models\CompanyProfile;
use Illuminate\Database\Seeder;
use RuntimeException;

class CompanyProfileSeeder extends Seeder
{
    public function run(): void
    {
        $name = config('seeding.company.name');

        if (! $name) {
            throw new RuntimeException(
                'COMPANY_NAME must be configured.'
            );
        }

        CompanyProfile::updateOrCreate(
            ['id' => 1],
            [
                'name' => $name,
                'legal_name' => config('seeding.company.legal_name'),
                'registration_number' => config('seeding.company.registration_number'),
                'tax_identification_number' => config('seeding.company.tax_identification_number'),
                'email' => config('seeding.company.email'),
                'phone' => config('seeding.company.phone'),
                'website' => config('seeding.company.website'),
                'address_line_1' => config('seeding.company.address_line_1'),
                'address_line_2' => config('seeding.company.address_line_2'),
                'city' => config('seeding.company.city'),
                'province' => config('seeding.company.province'),
                'postal_code' => config('seeding.company.postal_code'),
                'country' => config('seeding.company.country'),
            ]
        );
    }
}
