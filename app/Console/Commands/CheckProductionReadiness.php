<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;

#[Signature('hris:production-check {--json : Output safe configuration checks as JSON}')]
#[Description('Check configuration for the single-server HTTPS deployment without connecting to services')]
class CheckProductionReadiness extends Command
{
    public function handle(): int
    {
        $origin = $this->publicOrigin(config('app.url'));
        $hostname = $origin === null ? null : parse_url($origin, PHP_URL_HOST);
        $key = (string) config('app.key');
        $key = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $database = config('database.connections.mysql', []);
        $reverb = config('reverb.apps.apps.0', []);
        $smtp = config('mail.mailers.smtp', []);
        $mailEnabled = config('hris_mail.enabled') === true;
        $telegramEnabled = config('telegram.enabled') === true;

        $checks = [
            $this->check('environment', config('app.env') === 'production' && config('app.debug') === false,
                'Use APP_ENV=production and APP_DEBUG=false.'),
            $this->check('encryption', is_string($key) && Encrypter::supported($key, (string) config('app.cipher')),
                'Configure a valid APP_KEY; preserve the original key when migrating encrypted data.'),
            $this->check('https_origin', $origin !== null
                && $this->publicOrigin(config('hris_mail.frontend_url')) === $origin
                && $this->publicOrigin(config('telegram.frontend_url')) === $origin,
                'APP_URL and FRONTEND_URL must use the same real HTTPS hostname on port 443.'),
            $this->check('cors', $origin !== null && config('cors.allowed_origins') === [$origin]
                && config('cors.allowed_origins_patterns') === [],
                'Allow only the production frontend origin.'),
            $this->check('session', config('session.driver') === 'database'
                && config('session.secure') === true && config('session.http_only') === true
                && config('session.encrypt') === true && config('session.same_site') === 'lax'
                && in_array(config('session.domain'), [null, ''], true),
                'Use encrypted database sessions with Secure, HttpOnly, SameSite=Lax and a host-only cookie.'),
            $this->check('database', config('database.default') === 'mysql'
                && in_array($database['url'] ?? null, [null, ''], true)
                && preg_match('/\A[a-zA-Z0-9_]{1,64}\z/D', (string) ($database['database'] ?? '')) === 1
                && $this->credential($database['username'] ?? null) && ($database['username'] ?? '') !== 'root'
                && $this->credential($database['password'] ?? null, 16),
                'Use a dedicated MySQL account, database and password of at least 16 characters; leave DB_URL unset.'),
            $this->check('workers', config('queue.default') === 'database' && config('cache.default') === 'database'
                && (int) config('queue.connections.database.retry_after') > 60,
                'Use database queue/cache and DB_QUEUE_RETRY_AFTER greater than the worker timeout of 60 seconds.'),
            $this->check('reverb_listener', config('broadcasting.default') === 'reverb'
                && config('reverb.servers.reverb.host') === '127.0.0.1'
                && (int) config('reverb.servers.reverb.port') === 8082
                && config('broadcasting.connections.reverb.options.host') === '127.0.0.1'
                && (int) config('broadcasting.connections.reverb.options.port') === 8082
                && config('broadcasting.connections.reverb.options.scheme') === 'http',
                'Keep Reverb and server-side publishing on private loopback port 8082; Nginx provides browser WSS on 443.'),
            $this->check('reverb_credentials', $this->credential($reverb['app_id'] ?? null)
                && $this->credential($reverb['key'] ?? null, 16) && $this->credential($reverb['secret'] ?? null, 32)
                && config('broadcasting.connections.reverb.key') === ($reverb['key'] ?? null)
                && config('broadcasting.connections.reverb.secret') === ($reverb['secret'] ?? null)
                && config('broadcasting.connections.reverb.app_id') === ($reverb['app_id'] ?? null)
                && ($reverb['allowed_origins'] ?? []) === [$hostname]
                && ($reverb['accept_client_events_from'] ?? 'none') === 'none',
                'Set independent Reverb credentials and exactly the public hostname as REVERB_ALLOWED_ORIGINS.'),
            $this->check('email', ! $mailEnabled || (config('mail.default') === 'smtp'
                && ($smtp['scheme'] ?? null) === 'smtps' && (int) ($smtp['port'] ?? 0) === 465
                && in_array($smtp['url'] ?? null, [null, ''], true) && ($smtp['verify_peer'] ?? true) === true
                && $this->credential($smtp['host'] ?? null) && $this->credential($smtp['username'] ?? null)
                && $this->credential($smtp['password'] ?? null)
                && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false),
                'This deployment uses authenticated SMTPS on port 465 with certificate verification and a valid sender; leave MAIL_URL unset.'),
            $this->check('telegram', ! $telegramEnabled || ($this->credential(config('telegram.bot_token'), 30)
                && (int) config('telegram.bot_id') > 0 && $this->credential(config('telegram.bot_username'))),
                'Enabled Telegram requires backend-only bot credentials; run polling on one server.'),
            $this->check('filesystem', config('filesystems.default') === 'local'
                && is_writable(storage_path()) && is_writable(storage_path('logs'))
                && is_writable(base_path('bootstrap/cache')),
                'The application user must be able to write storage and bootstrap/cache; use local files for this backup setup.'),
        ];
        $ready = ! in_array(false, array_column($checks, 'passed'), true);

        if ($this->option('json')) {
            $this->line(json_encode(['ready' => $ready, 'scope' => 'configuration_only', 'checks' => $checks], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            foreach ($checks as $check) {
                $this->line(($check['passed'] ? 'PASS' : 'FAIL').' '.$check['check'].': '.$check['message']);
            }
            $this->comment('Configuration checks only. TLS, migrations, service recovery, delivery, backups and browser UAT still need verification on the target server.');
        }

        return $ready ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{check: string, passed: bool, message: string} */
    private function check(string $name, bool $passed, string $message): array
    {
        return ['check' => $name, 'passed' => $passed, 'message' => $message];
    }

    private function credential(mixed $value, int $minimum = 1): bool
    {
        return is_string($value) && strlen(trim($value)) >= $minimum
            && preg_match('/change.?me|replace|placeholder|example/i', $value) !== 1;
    }

    private function publicOrigin(mixed $url): ?string
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($url);
        $hostname = strtolower($parts['host'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || ($parts['port'] ?? 443) !== 443
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || filter_var($hostname, FILTER_VALIDATE_IP) !== false
            || filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || ! str_contains($hostname, '.')
            || preg_match('/(?:\A|\.)(?:example\.(?:com|net|org)|localhost|test|invalid)\z/D', $hostname) === 1) {
            return null;
        }

        return 'https://'.$hostname;
    }
}
