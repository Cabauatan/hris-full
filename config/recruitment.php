<?php

$iceServers = json_decode((string) env('RECRUITMENT_ICE_SERVERS', '[]'), true);

return [
    'ice_servers' => is_array($iceServers) && array_is_list($iceServers) ? $iceServers : [],
    'early_join_minutes' => 15,
    'grace_minutes' => 30,
];
