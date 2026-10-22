<?php

/** Standalone validation: never boots Laravel, connects to a database, or sends a message. */
return new class
{
    /** @param array<string, string> $environment
     * @return array{backend_host: string, frontend_host: string, port: int, run_migrations: bool, proxy_key: string}
     */
    public function validate(array $environment): array
    {
        $value = static fn (string $name, string $default = ''): string => (string) ($environment[$name] ?? $default);
        $require = static function (bool $condition, string $message): void {
            if (! $condition) {
                throw new RuntimeException($message);
            }
        };

        $require($value('RENDER') === 'true', 'This entry point requires a Render service.');
        $require($value('APP_ENV') === 'production' && $value('APP_DEBUG') === 'false', 'APP_ENV must be production and APP_DEBUG must be false.');
        $require(in_array($value('APP_CIPHER', 'AES-256-CBC'), ['AES-256-CBC', 'AES-256-GCM'], true), 'APP_CIPHER must use AES-256 encryption.');
        $key = $value('APP_KEY');
        $decodedKey = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $require(is_string($decodedKey) && strlen($decodedKey) === 32, 'APP_KEY must be a valid existing 32-byte encryption key; it is never generated at startup.');

        $backend = $this->origin($value('APP_URL'));
        $frontend = $this->origin($value('FRONTEND_URL'));
        $require($backend !== null && $frontend !== null, 'APP_URL and FRONTEND_URL must be public HTTPS origins without paths, ports, or credentials.');
        $require($backend === $value('RENDER_EXTERNAL_HOSTNAME') && str_ends_with($backend, '.onrender.com'), 'APP_URL must match this Render service hostname.');
        $require(preg_match('/\A[a-f0-9]{64}\z/', $value('HRIS_PROXY_KEY')) === 1, 'HRIS_PROXY_KEY must be an independent 64-character lowercase hexadecimal secret shared only with the Pages Worker.');
        $require($value('ACK_DEDICATED_DATABASE') === 'true', 'Set ACK_DEDICATED_DATABASE=true only for a separate HRIS database, never the ticketing database.');
        $require($value('ACK_EPHEMERAL_STORAGE') === 'true', 'Render Free loses uploads and generated payslip files on restart, redeploy, or sleep. Set ACK_EPHEMERAL_STORAGE=true only for an accepted preview; durable HR files require persistent storage.');
        $require($value('DB_CONNECTION') === 'mysql' && $value('DB_URL') === '' && $value('DB_SOCKET') === '', 'Use explicit dedicated MySQL settings without DB_URL or DB_SOCKET overrides.');
        $require($this->hostname($value('DB_HOST')) && ! in_array(strtolower($value('DB_HOST')), ['localhost', 'mysql', 'mariadb'], true), 'DB_HOST must name an external database server.');
        $require(ctype_digit($value('DB_PORT', '3306')) && (int) $value('DB_PORT', '3306') >= 1 && (int) $value('DB_PORT', '3306') <= 65535, 'DB_PORT must be a valid port.');
        $require(preg_match('/\A[a-zA-Z][a-zA-Z0-9_]{0,63}\z/', $value('DB_DATABASE')) === 1
            && ! in_array(strtolower($value('DB_DATABASE')), ['mysql', 'sys', 'information_schema', 'performance_schema', 'defaultdb', 'ticketing', 'ticketing_system', 'laravel', 'test'], true), 'DB_DATABASE must be a dedicated HRIS schema, not a default, system, or ticketing schema.');
        $require(trim($value('DB_USERNAME')) !== '' && ! in_array(strtolower($value('DB_USERNAME')), ['root', 'admin'], true)
            && strlen($value('DB_PASSWORD')) >= 12, 'Use a dedicated database account with a nonempty strong password; root/admin are refused.');
        $certificate = $value('MYSQL_ATTR_SSL_CA');
        $require($certificate !== '' && is_file($certificate) && is_readable($certificate), 'MYSQL_ATTR_SSL_CA must point to a readable database CA secret file.');
        $certificateContents = @file_get_contents($certificate);
        $require(is_string($certificateContents) && @openssl_x509_parse($certificateContents) !== false, 'The database CA secret file must contain a complete PEM certificate.');

        foreach (['RUN_MIGRATIONS' => 'false', 'RUN_REVERB' => 'true', 'RUN_QUEUE_WORKER' => 'true', 'RUN_SCHEDULER' => 'true', 'RUN_TELEGRAM_POLLER' => 'false', 'TELEGRAM_ENABLED' => 'false', 'HRIS_EMAIL_ENABLED' => 'false'] as $name => $default) {
            $require(in_array($value($name, $default), ['true', 'false'], true), $name.' must be exactly true or false.');
        }
        $require($value('RUN_REVERB', 'true') === 'true' && $value('RUN_QUEUE_WORKER', 'true') === 'true' && $value('RUN_SCHEDULER', 'true') === 'true', 'This single-service deployment requires its Reverb, queue, and scheduler processes.');
        $require(preg_match('/\A[a-zA-Z0-9_-]{8,128}\z/', $value('REVERB_APP_KEY')) === 1
            && preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/', $value('REVERB_APP_ID')) === 1
            && strlen($value('REVERB_APP_SECRET')) >= 32, 'Configure independent Reverb app ID, public key, and a backend-only secret of at least 32 characters.');

        $port = $value('PORT', '10000');
        $require(ctype_digit($port) && (int) $port >= 1024 && (int) $port <= 65535
            && ! in_array((int) $port, [8082, 9000, 18012, 18013, 19099], true), 'PORT must be a nonreserved unprivileged HTTP port distinct from FPM and Reverb.');

        if ($value('TELEGRAM_ENABLED', 'false') === 'true') {
            $require(preg_match('/\A[0-9]+:[a-zA-Z0-9_-]{20,}\z/', $value('TELEGRAM_BOT_TOKEN')) === 1
                && preg_match('/\A[a-zA-Z0-9_]{5,32}bot\z/i', $value('TELEGRAM_BOT_USERNAME')) === 1
                && ctype_digit($value('TELEGRAM_BOT_ID')) && (int) $value('TELEGRAM_BOT_ID') > 0, 'Enabled Telegram requires valid backend-only bot credentials.');
        }
        $require($value('RUN_TELEGRAM_POLLER', 'false') !== 'true' || $value('TELEGRAM_ENABLED', 'false') === 'true', 'RUN_TELEGRAM_POLLER=true requires TELEGRAM_ENABLED=true and no other active bot poller.');

        if ($value('HRIS_EMAIL_ENABLED', 'false') === 'true') {
            $mailPort = $value('MAIL_PORT');
            $require($value('MAIL_MAILER') === 'smtp' && $value('MAIL_URL') === '', 'Enabled email requires explicit SMTP settings without a MAIL_URL override.');
            $require(ctype_digit($mailPort) && (int) $mailPort >= 1 && (int) $mailPort <= 65535
                && ! in_array((int) $mailPort, [25, 465, 587], true), 'Render Free blocks SMTP ports 25, 465, and 587; use a supported provider port such as 2525.');
            $require(in_array($value('MAIL_SCHEME'), ['smtp', 'smtps'], true) && $this->hostname($value('MAIL_HOST'))
                && $value('MAIL_USERNAME') !== '' && $value('MAIL_PASSWORD') !== ''
                && filter_var($value('MAIL_FROM_ADDRESS'), FILTER_VALIDATE_EMAIL) !== false, 'Enabled email requires complete SMTP credentials, a sender address, and a TLS-capable provider.');
        }

        return ['backend_host' => $backend, 'frontend_host' => $frontend, 'port' => (int) $port, 'run_migrations' => $value('RUN_MIGRATIONS', 'false') === 'true', 'proxy_key' => $value('HRIS_PROXY_KEY')];
    }

    /** @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    public function hardenConfiguration(array $configuration): array
    {
        // The installed SMTP transport supports required STARTTLS and verifies
        // peers by default. Persist both requirements for FPM and every worker.
        $configuration['mail']['mailers']['smtp']['require_tls'] = true;
        $configuration['mail']['mailers']['smtp']['verify_peer'] = true;

        return $configuration;
    }

    /** @param array{backend_host: string, frontend_host: string, port: int, run_migrations: bool, proxy_key: string} $settings */
    public function nginx(array $settings, string $template): string
    {
        $octet = '(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])';
        $ipv4 = '(?:'.$octet.'\.){3}'.$octet;
        $group = '[0-9a-fA-F]{1,4}';
        $ipv6 = '(?:(?:'.$group.':){7}'.$group
            .'|(?:'.$group.':){1,7}:'
            .'|(?:'.$group.':){1,6}:'.$group
            .'|(?:'.$group.':){1,5}(?::'.$group.'){1,2}'
            .'|(?:'.$group.':){1,4}(?::'.$group.'){1,3}'
            .'|(?:'.$group.':){1,3}(?::'.$group.'){1,4}'
            .'|(?:'.$group.':){1,2}(?::'.$group.'){1,5}'
            .'|'.$group.':(?:(?::'.$group.'){1,6})'
            .'|:(?:(?::'.$group.'){1,7}|:)'
            .'|(?:(?:'.$group.':){6}|::(?:'.$group.':){0,4}|(?:'.$group.':){1,5}:)'.$ipv4.')';

        return str_replace(
            ['__PORT__', '__BACKEND_HOST__', '__PROXY_KEY__', '__IP_PATTERN__'],
            [(string) $settings['port'], $settings['backend_host'], $settings['proxy_key'], '(?:'.$ipv4.'|'.$ipv6.')'],
            $template,
        );
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https'
            || ! $this->hostname($parts['host'] ?? '')
            || count(array_diff(array_keys($parts), ['scheme', 'host', 'path'])) !== 0
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }

        return $parts['host'];
    }

    private function hostname(string $hostname): bool
    {
        if (strlen($hostname) > 253 || ! str_contains($hostname, '.')
            || filter_var($hostname, FILTER_VALIDATE_IP) !== false
            || preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/', $hostname) !== 1
            || preg_match('/(?:^|\.)(?:localhost|local|internal|test|invalid)\z/', $hostname) === 1) {
            return false;
        }

        foreach (explode('.', $hostname) as $label) {
            if (strlen($label) > 63 || preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/', $label) !== 1) {
                return false;
            }
        }

        return true;
    }
};
