<?php

return [
    'admin' => [
        'name' => env('ADMIN_NAME'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
        'employee_number' => env('ADMIN_EMPLOYEE_NUMBER'),
        'first_name' => env('ADMIN_FIRST_NAME'),
        'middle_name' => env('ADMIN_MIDDLE_NAME'),
        'last_name' => env('ADMIN_LAST_NAME'),
        'hire_date' => env('ADMIN_HIRE_DATE'),
        'employment_type' => env('ADMIN_EMPLOYMENT_TYPE'),
    ],
    'company' => [
        'name' => env('COMPANY_NAME'),
        'legal_name' => env('COMPANY_LEGAL_NAME'),
        'registration_number' => env('COMPANY_REGISTRATION_NUMBER'),
        'tax_identification_number' => env('COMPANY_TIN'),
        'email' => env('COMPANY_EMAIL'),
        'phone' => env('COMPANY_PHONE'),
        'website' => env('COMPANY_WEBSITE'),
        'address_line_1' => env('COMPANY_ADDRESS_LINE_1'),
        'address_line_2' => env('COMPANY_ADDRESS_LINE_2'),
        'city' => env('COMPANY_CITY'),
        'province' => env('COMPANY_PROVINCE'),
        'postal_code' => env('COMPANY_POSTAL_CODE'),
        'country' => env('COMPANY_COUNTRY', 'Philippines'),
    ],
];
