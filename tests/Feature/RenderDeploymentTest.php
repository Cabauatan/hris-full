<?php

namespace Tests\Feature;

use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RenderDeploymentTest extends TestCase
{
    public function test_valid_preview_configuration_is_checked_without_database_network_or_migration_side_effects(): void
    {
        DB::shouldReceive('connection')->never();
        $runtime = require base_path('resources/deployment/render/runtime-settings.php');

        $settings = $runtime->validate($this->environment());

        $this->assertSame(['backend_host' => 'hris-preview.onrender.com', 'frontend_host' => 'hris-preview.pages.dev', 'port' => 10000, 'run_migrations' => false, 'proxy_key' => str_repeat('a', 64)], $settings);
        Http::assertNothingSent();
    }

    #[DataProvider('unsafeEnvironment')]
    public function test_unsafe_startup_settings_fail_before_booting_laravel_and_do_not_print_secrets(array $changes, string $message): void
    {
        $process = $this->check($changes);

        $this->assertSame(1, $process->getExitCode(), $process->getOutput());
        $this->assertStringContainsString($message, $process->getErrorOutput());
        foreach (['fixture-db-password-123', 'fixture-reverb-secret-12345678901234567890', base64_encode(str_repeat('k', 32)), 'secret-in-url'] as $secret) {
            $this->assertStringNotContainsString($secret, $process->getOutput().$process->getErrorOutput());
        }
    }

    public static function unsafeEnvironment(): array
    {
        return [
            'not Render' => [['RENDER' => 'false'], 'requires a Render service'],
            'development' => [['APP_ENV' => 'local'], 'APP_ENV must be production'],
            'debug' => [['APP_DEBUG' => 'true'], 'APP_DEBUG must be false'],
            'unsupported cipher' => [['APP_CIPHER' => 'AES-128-CBC'], 'AES-256'],
            'missing encryption key' => [['APP_KEY' => ''], 'APP_KEY must be a valid'],
            'invalid base64 key' => [['APP_KEY' => 'base64:not!base64'], 'APP_KEY must be a valid'],
            'short encryption key' => [['APP_KEY' => 'base64:c2hvcnQ='], 'APP_KEY must be a valid'],
            'plaintext backend' => [['APP_URL' => 'http://hris-preview.onrender.com'], 'public HTTPS origins'],
            'backend credentials' => [['APP_URL' => 'https://user:secret-in-url@hris-preview.onrender.com'], 'public HTTPS origins'],
            'backend URL path' => [['APP_URL' => 'https://hris-preview.onrender.com/api'], 'public HTTPS origins'],
            'backend URL port' => [['APP_URL' => 'https://hris-preview.onrender.com:443'], 'public HTTPS origins'],
            'different Render service' => [['RENDER_EXTERNAL_HOSTNAME' => 'ticketing.onrender.com'], 'match this Render service'],
            'missing Worker shared key' => [['HRIS_PROXY_KEY' => ''], 'HRIS_PROXY_KEY must be'],
            'Worker shared key injection' => [['HRIS_PROXY_KEY' => 'secret-in-url; server{}'], 'HRIS_PROXY_KEY must be'],
            'frontend LAN' => [['FRONTEND_URL' => 'https://192.168.100.51'], 'public HTTPS origins'],
            'frontend wildcard' => [['FRONTEND_URL' => 'https://*.pages.dev'], 'public HTTPS origins'],
            'frontend query' => [['FRONTEND_URL' => 'https://hris-preview.pages.dev?key=secret-in-url'], 'public HTTPS origins'],
            'frontend fragment' => [['FRONTEND_URL' => 'https://hris-preview.pages.dev#secret-in-url'], 'public HTTPS origins'],
            'frontend hostname injection' => [['FRONTEND_URL' => "https://hris-preview.pages.dev\nserver{}"], 'public HTTPS origins'],
            'unacknowledged dedicated database' => [['ACK_DEDICATED_DATABASE' => 'false'], 'separate HRIS database'],
            'unacknowledged file loss' => [['ACK_EPHEMERAL_STORAGE' => 'false'], 'loses uploads'],
            'SQLite data loss' => [['DB_CONNECTION' => 'sqlite'], 'explicit dedicated MySQL'],
            'database URL override' => [['DB_URL' => 'mysql://secret-in-url'], 'without DB_URL'],
            'database socket override' => [['DB_SOCKET' => '/tmp/mysql.sock'], 'without DB_URL'],
            'loopback database' => [['DB_HOST' => '127.0.0.1'], 'external database server'],
            'database port injection' => [['DB_PORT' => '3306;echo secret-in-url'], 'DB_PORT must be'],
            'system schema' => [['DB_DATABASE' => 'mysql'], 'dedicated HRIS schema'],
            'shared provider schema' => [['DB_DATABASE' => 'defaultdb'], 'dedicated HRIS schema'],
            'ticketing schema' => [['DB_DATABASE' => 'ticketing_system'], 'dedicated HRIS schema'],
            'database schema injection' => [['DB_DATABASE' => 'hris;DROP DATABASE x'], 'dedicated HRIS schema'],
            'root database account' => [['DB_USERNAME' => 'root'], 'dedicated database account'],
            'weak database password' => [['DB_PASSWORD' => 'short'], 'dedicated database account'],
            'missing database TLS CA' => [['MYSQL_ATTR_SSL_CA' => ''], 'readable database CA'],
            'invalid migrations flag' => [['RUN_MIGRATIONS' => 'yes'], 'exactly true or false'],
            'disabled Reverb' => [['RUN_REVERB' => 'false'], 'requires its Reverb'],
            'disabled queue worker' => [['RUN_QUEUE_WORKER' => 'false'], 'requires its Reverb'],
            'disabled scheduler' => [['RUN_SCHEDULER' => 'false'], 'requires its Reverb'],
            'missing Reverb key' => [['REVERB_APP_KEY' => ''], 'independent Reverb'],
            'weak Reverb secret' => [['REVERB_APP_SECRET' => 'short'], 'independent Reverb'],
            'privileged HTTP port' => [['PORT' => '80'], 'nonreserved unprivileged'],
            'FPM port collision' => [['PORT' => '9000'], 'distinct from FPM'],
            'Reverb port collision' => [['PORT' => '8082'], 'distinct from FPM'],
            'Render reserved port' => [['PORT' => '18012'], 'nonreserved unprivileged'],
            'HTTP port injection' => [['PORT' => '10000;return 200'], 'nonreserved unprivileged'],
            'Telegram missing credentials' => [['TELEGRAM_ENABLED' => 'true'], 'backend-only bot credentials'],
            'poller without Telegram' => [['RUN_TELEGRAM_POLLER' => 'true'], 'requires TELEGRAM_ENABLED'],
            'mail URL override' => [['HRIS_EMAIL_ENABLED' => 'true', 'MAIL_MAILER' => 'smtp', 'MAIL_URL' => 'smtp://secret-in-url'], 'without a MAIL_URL'],
            'Gmail SMTP blocked on free plan' => [['HRIS_EMAIL_ENABLED' => 'true', 'MAIL_MAILER' => 'smtp', 'MAIL_PORT' => '465'], 'blocks SMTP ports'],
            'submission SMTP blocked on free plan' => [['HRIS_EMAIL_ENABLED' => 'true', 'MAIL_MAILER' => 'smtp', 'MAIL_PORT' => '587'], 'blocks SMTP ports'],
            'plaintext SMTP blocked on free plan' => [['HRIS_EMAIL_ENABLED' => 'true', 'MAIL_MAILER' => 'smtp', 'MAIL_PORT' => '25'], 'blocks SMTP ports'],
            'mail missing credentials' => [['HRIS_EMAIL_ENABLED' => 'true', 'MAIL_MAILER' => 'smtp', 'MAIL_PORT' => '2525'], 'complete SMTP credentials'],
        ];
    }

    public function test_valid_cli_check_prints_only_a_safe_confirmation(): void
    {
        $process = $this->check();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame("Render runtime configuration validated without database or network access.\n", $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
    }

    public function test_explicit_pending_migration_opt_in_only_changes_the_validated_plan(): void
    {
        DB::shouldReceive('connection')->never();
        $runtime = require base_path('resources/deployment/render/runtime-settings.php');

        $settings = $runtime->validate(array_replace($this->environment(), ['RUN_MIGRATIONS' => 'true']));

        $this->assertTrue($settings['run_migrations']);
        Http::assertNothingSent();
    }

    public function test_malformed_certificate_file_is_rejected_without_printing_its_contents(): void
    {
        $directory = $this->directory();
        try {
            file_put_contents($directory.'/ca.pem', 'fixture-private-secret');

            $process = $this->check(['MYSQL_ATTR_SSL_CA' => $directory.'/ca.pem']);

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('complete PEM certificate', $process->getErrorOutput());
            $this->assertStringNotContainsString('fixture-private-secret', $process->getOutput().$process->getErrorOutput());
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_supported_smtp_port_is_accepted_and_all_cached_mailers_require_verified_tls(): void
    {
        $runtime = require base_path('resources/deployment/render/runtime-settings.php');
        $settings = array_replace($this->environment(), [
            'HRIS_EMAIL_ENABLED' => 'true', 'MAIL_MAILER' => 'smtp', 'MAIL_SCHEME' => 'smtp',
            'MAIL_HOST' => 'smtp.provider.com', 'MAIL_PORT' => '2525',
            'MAIL_USERNAME' => 'fixture-user', 'MAIL_PASSWORD' => 'fixture-mail-secret', 'MAIL_FROM_ADDRESS' => 'hris@acme.ph',
        ]);
        $configuration = $runtime->hardenConfiguration(['mail' => [
            'default' => 'smtp',
            'mailers' => ['smtp' => ['transport' => 'smtp', 'scheme' => 'smtp', 'host' => 'smtp.provider.com', 'port' => 2525, 'username' => 'fixture-user', 'password' => 'fixture-mail-secret', 'require_tls' => false, 'verify_peer' => false]],
            'from' => ['address' => 'hris@acme.ph', 'name' => 'HRIS'],
        ]]);
        config($configuration);

        $runtime->validate($settings);
        $transport = (new MailManager($this->app))->mailer('smtp')->getSymfonyTransport();

        $this->assertTrue($transport->isTlsRequired());
        $this->assertTrue($transport->isAutoTls());
        $this->assertNotSame(false, $transport->getStream()->getStreamOptions()['ssl']['verify_peer'] ?? true);
        Http::assertNothingSent();
    }

    public function test_isolated_native_nginx_blocks_private_paths_and_wrong_hosts(): void
    {
        $nginx = getenv('HRIS_TEST_NGINX_BINARY') ?: (PHP_OS_FAMILY === 'Windows'
            ? 'C:/Program Files/Herd/resources/app.asar.unpacked/resources/bin/nginx/nginx.exe'
            : '/usr/sbin/nginx');
        if (! is_file($nginx)) {
            $this->markTestSkipped('A native Nginx binary is not installed; container validation is still required on Render.');
        }

        $directory = $this->directory();
        $process = null;
        try {
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
            $this->assertIsResource($socket, $errorMessage);
            $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
            fclose($socket);
            $runtime = require base_path('resources/deployment/render/runtime-settings.php');
            $settings = $runtime->validate(array_replace($this->environment(), ['PORT' => (string) $port]));
            $site = $runtime->nginx($settings, file_get_contents(base_path('resources/deployment/render/nginx-site.conf.template')));
            $parameters = str_replace('\\', '/', dirname($nginx).'/conf/fastcgi_params');
            if (! is_file($parameters)) {
                $parameters = '/etc/nginx/fastcgi_params';
            }
            $site = str_replace(['listen '.$port.' default_server;', 'include fastcgi_params;'], ['listen 127.0.0.1:'.$port.' default_server;', 'include "'.$parameters.'";'], $site);
            $site = substr($site, 0, strrpos($site, '}')).'location = /__fixture_client_ip { return 200 "$hris_client_ip"; }'.PHP_EOL.'}';
            file_put_contents($directory.'/site.conf', $site);
            $prefix = str_replace('\\', '/', $directory);
            $configuration = 'worker_processes 1; pid "'.$prefix.'/nginx.pid"; error_log "'.$prefix.'/error.log"; events { worker_connections 32; } http { access_log off; map_hash_bucket_size 128; map $http_upgrade $connection_upgrade { default upgrade; "" close; } include "'.$prefix.'/site.conf"; }';
            file_put_contents($directory.'/nginx.conf', $configuration);
            File::ensureDirectoryExists($directory.'/logs');
            File::ensureDirectoryExists($directory.'/temp');

            $syntax = new Process([$nginx, '-p', $prefix.'/', '-c', $prefix.'/nginx.conf', '-t'], $directory);
            $syntax->run();
            $this->assertSame(0, $syntax->getExitCode(), $syntax->getErrorOutput());
            $process = new Process([$nginx, '-p', $prefix.'/', '-c', $prefix.'/nginx.conf', '-g', 'daemon off;'], $directory);
            $process->start();
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $probe = @stream_socket_client('tcp://127.0.0.1:'.$port, $errorCode, $errorMessage, 0.1);
                if (is_resource($probe)) {
                    fclose($probe);
                    break;
                }
                usleep(20_000);
            }

            foreach (['/', '/.env', '/.git/config', '/storage/employee-documents/private.pdf', '/apps/hris/events', '/index.php', '/arbitrary.php/path', '/api/hidden.php', '/api/.env'] as $path) {
                $response = $this->request($port, $path, 'hris-preview.onrender.com');
                $this->assertStringStartsWith('HTTP/1.1 404 ', $response, $path);
            }
            $this->assertSame('', $this->request($port, '/up', 'unrelated.onrender.com'));
            $this->assertStringEndsWith('203.0.113.7', $this->request($port, '/__fixture_client_ip', 'hris-preview.onrender.com', ['CF-Connecting-IP' => '203.0.113.7', 'X-Hris-Proxy-Key' => 'wrong', 'X-Hris-Client-IP' => '198.51.100.9']));
            $this->assertStringEndsWith('198.51.100.9', $this->request($port, '/__fixture_client_ip', 'hris-preview.onrender.com', ['CF-Connecting-IP' => '203.0.113.7', 'X-Hris-Proxy-Key' => str_repeat('a', 64), 'X-Hris-Client-IP' => '198.51.100.9']));
            $this->assertStringEndsWith('203.0.113.7', $this->request($port, '/__fixture_client_ip', 'hris-preview.onrender.com', ['CF-Connecting-IP' => '203.0.113.7', 'X-Hris-Proxy-Key' => str_repeat('a', 64), 'X-Hris-Client-IP' => '999.51.100.9']));
            $this->assertStringEndsWith('2001:db8::9', $this->request($port, '/__fixture_client_ip', 'hris-preview.onrender.com', ['CF-Connecting-IP' => '203.0.113.7', 'X-Hris-Proxy-Key' => str_repeat('a', 64), 'X-Hris-Client-IP' => '2001:db8::9']));
            $this->assertStringEndsWith('127.0.0.1', $this->request($port, '/__fixture_client_ip', 'hris-preview.onrender.com', ['CF-Connecting-IP' => '::::', 'X-Hris-Proxy-Key' => str_repeat('a', 64), 'X-Hris-Client-IP' => '::::']));
        } finally {
            if ($process?->isRunning()) {
                $stop = new Process([$nginx, '-p', str_replace('\\', '/', $directory).'/', '-c', str_replace('\\', '/', $directory).'/nginx.conf', '-s', 'quit'], $directory);
                $stop->run();
                $process->wait();
            }
            File::deleteDirectory($directory);
        }
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        $locations = openssl_get_cert_locations();
        $certificate = collect([ini_get('openssl.cafile'), $locations['default_cert_file'], '/etc/ssl/certs/ca-certificates.crt', '/etc/ssl/cert.pem'])
            ->first(fn ($path): bool => is_string($path) && $path !== '' && is_file($path));
        $this->assertIsString($certificate, 'The runtime needs an installed public CA bundle for the isolated certificate fixture.');

        return [
            'RENDER' => 'true', 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'APP_CIPHER' => 'AES-256-CBC', 'APP_URL' => 'https://hris-preview.onrender.com', 'FRONTEND_URL' => 'https://hris-preview.pages.dev',
            'RENDER_EXTERNAL_HOSTNAME' => 'hris-preview.onrender.com', 'ACK_DEDICATED_DATABASE' => 'true', 'ACK_EPHEMERAL_STORAGE' => 'true',
            'HRIS_PROXY_KEY' => str_repeat('a', 64),
            'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_SOCKET' => '', 'DB_HOST' => 'mysql.provider.com', 'DB_PORT' => '3306',
            'DB_DATABASE' => 'hris_preview', 'DB_USERNAME' => 'hris_preview_user', 'DB_PASSWORD' => 'fixture-db-password-123', 'MYSQL_ATTR_SSL_CA' => $certificate,
            'REVERB_APP_ID' => 'hris', 'REVERB_APP_KEY' => 'fixture-public-key', 'REVERB_APP_SECRET' => 'fixture-reverb-secret-12345678901234567890',
            'RUN_MIGRATIONS' => 'false', 'RUN_REVERB' => 'true', 'RUN_SCHEDULER' => 'true', 'RUN_QUEUE_WORKER' => 'true', 'RUN_TELEGRAM_POLLER' => 'false',
            'TELEGRAM_ENABLED' => 'false', 'TELEGRAM_BOT_TOKEN' => '', 'TELEGRAM_BOT_USERNAME' => '', 'TELEGRAM_BOT_ID' => '',
            'HRIS_EMAIL_ENABLED' => 'false', 'MAIL_MAILER' => 'smtp', 'MAIL_PORT' => '2525', 'MAIL_URL' => '', 'MAIL_SCHEME' => '', 'MAIL_HOST' => '', 'MAIL_USERNAME' => '', 'MAIL_PASSWORD' => '', 'MAIL_FROM_ADDRESS' => '',
            'PORT' => '10000',
        ];
    }

    private function check(array $changes = []): Process
    {
        $process = new Process([PHP_BINARY, base_path('resources/deployment/render/configure.php'), 'check'], base_path(), array_replace($this->environment(), $changes));
        $process->setTimeout(15);
        $process->run();

        return $process;
    }

    private function directory(): string
    {
        $path = storage_path('framework/testing/render-'.bin2hex(random_bytes(8)));
        File::makeDirectory($path, 0700, true);

        return $path;
    }

    private function request(int $port, string $path, string $host, array $headers = []): string
    {
        $socket = stream_socket_client('tcp://127.0.0.1:'.$port, $errorCode, $errorMessage, 2);
        if ($socket === false) {
            throw new RuntimeException('The isolated Nginx fixture did not start.');
        }
        stream_set_timeout($socket, 3);
        $extra = '';
        foreach ($headers as $name => $value) {
            $extra .= $name.': '.$value."\r\n";
        }
        fwrite($socket, 'GET '.$path." HTTP/1.1\r\nHost: ".$host."\r\n".$extra."Connection: close\r\n\r\n");
        $response = stream_get_contents($socket);
        fclose($socket);

        return $response;
    }
}
