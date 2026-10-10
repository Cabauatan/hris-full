<?php

return [
    'enabled' => (bool) env('TELEGRAM_ENABLED', false),
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'bot_username' => env('TELEGRAM_BOT_USERNAME'),
    'bot_id' => (int) env('TELEGRAM_BOT_ID', 0),
    'frontend_url' => env('FRONTEND_URL', 'http://127.0.0.1:5002'),
];
