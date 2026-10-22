<?php

$runtime = require __DIR__.'/runtime-settings.php';

try {
    $settings = $runtime->validate(getenv());

    switch ($argv[1] ?? '') {
        case 'check':
            fwrite(STDOUT, "Render runtime configuration validated without database or network access.\n");
            break;
        case 'hostname':
            fwrite(STDOUT, $settings['backend_host']);
            break;
        case 'frontend-hostname':
            fwrite(STDOUT, $settings['frontend_host']);
            break;
        case 'nginx':
            $template = file_get_contents(__DIR__.'/nginx-site.conf.template');
            if ($template === false || file_put_contents('/etc/nginx/http.d/default.conf', $runtime->nginx($settings, $template)) === false) {
                throw new RuntimeException('Could not prepare the Nginx site.');
            }
            break;
        case 'secure-mail':
            $path = dirname(__DIR__, 3).'/bootstrap/cache/config.php';
            if (! is_file($path)) {
                throw new RuntimeException('Laravel configuration must be cached before SMTP hardening.');
            }
            $configuration = require $path;
            if (! is_array($configuration)) {
                throw new RuntimeException('Laravel cached configuration is invalid.');
            }
            $contents = '<?php return '.var_export($runtime->hardenConfiguration($configuration), true).';'.PHP_EOL;
            if (file_put_contents($path, $contents, LOCK_EX) === false || ! chmod($path, 0600)) {
                throw new RuntimeException('Could not secure the cached mail transport configuration.');
            }
            break;
        default:
            throw new RuntimeException('Use check, hostname, frontend-hostname, nginx, or secure-mail.');
    }
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'Startup error: '.$exception->getMessage().PHP_EOL);
    exit(1);
}
