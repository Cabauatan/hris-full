<?php

return [
    'enabled' => (bool) env('HRIS_EMAIL_ENABLED', false),
    'frontend_url' => env('FRONTEND_URL', 'http://127.0.0.1:5002'),
];
