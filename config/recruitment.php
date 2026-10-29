<?php

$iceServers = json_decode((string) env('RECRUITMENT_ICE_SERVERS', '[{"urls":["stun:stun.cloudflare.com:3478"]}]'), true);

return [
    'ice_servers' => $iceServers,
    'turn' => [
        'key_id' => env('RECRUITMENT_TURN_KEY_ID', ''),
        'api_token' => env('RECRUITMENT_TURN_API_TOKEN', ''),
    ],
    'early_join_minutes' => 15,
    'grace_minutes' => 30,
];
