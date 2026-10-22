<?php

use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('broadcasting.default') !== 'reverb' || (int) config('reverb.servers.reverb.port') !== 8082) {
    fwrite(STDERR, "The PC tunnel expects the existing Reverb service on port 8082.\n");
    exit(1);
}
echo json_encode([
    'reverbKey' => config('reverb.apps.apps.0.key'),
    'reverbOrigins' => config('reverb.apps.apps.0.allowed_origins'),
    'frontendUrl' => config('hris_mail.frontend_url'),
    'telegramEnabled' => config('telegram.enabled'),
], JSON_THROW_ON_ERROR);
