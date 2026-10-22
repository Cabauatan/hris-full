<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    public function test_valid_production_configuration_passes_without_accessing_database_or_network(): void
    {
        $this->configureProduction();
        DB::shouldReceive('connection')->never();

        $status = Artisan::call('hris:production-check', ['--json' => true]);

        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $status);
        $this->assertTrue($result['ready']);
        $this->assertSame('configuration_only', $result['scope']);
        $this->assertCount(12, $result['checks']);
        $this->assertNotContains(false, array_column($result['checks'], 'passed'));
        Http::assertNothingSent();
    }

    #[DataProvider('unsafeSettings')]
    public function test_unsafe_configuration_fails_with_a_safe_named_check(array $settings, string $failedCheck): void
    {
        $this->configureProduction();
        config($settings);

        $status = Artisan::call('hris:production-check', ['--json' => true]);

        $output = Artisan::output();
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $status);
        $this->assertFalse($result['ready']);
        $checks = array_column($result['checks'], 'passed', 'check');
        $this->assertFalse($checks[$failedCheck]);
        foreach (['fixture-production-password-123', str_repeat('k', 32), str_repeat('s', 40), 'invalid-secret-fixture'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
        Http::assertNothingSent();
    }

    public static function unsafeSettings(): array
    {
        return [
            'local environment' => [['app.env' => 'local'], 'environment'],
            'debug enabled' => [['app.debug' => true], 'environment'],
            'invalid encryption key' => [['app.key' => 'invalid-secret-fixture'], 'encryption'],
            'http public URL' => [['app.url' => 'http://hris.acme.ph'], 'https_origin'],
            'placeholder hostname' => [['app.url' => 'https://hris.example.com'], 'https_origin'],
            'LAN hostname' => [['app.url' => 'https://192.168.100.51'], 'https_origin'],
            'mismatched frontend' => [['hris_mail.frontend_url' => 'https://other.acme.ph'], 'https_origin'],
            'URL credentials' => [['app.url' => 'https://user:pass@hris.acme.ph'], 'https_origin'],
            'insecure cookie' => [['session.secure' => false], 'session'],
            'broad cookie domain' => [['session.domain' => '.acme.ph'], 'session'],
            'unencrypted session' => [['session.encrypt' => false], 'session'],
            'wildcard CORS' => [['cors.allowed_origins' => ['*']], 'cors'],
            'local CORS in production' => [['cors.allowed_origins' => ['https://hris.acme.ph', 'http://localhost:5002']], 'cors'],
            'root database account' => [['database.connections.mysql.username' => 'root'], 'database'],
            'weak database password' => [['database.connections.mysql.password' => 'short'], 'database'],
            'database URL override' => [['database.connections.mysql.url' => 'mysql://invalid-secret-fixture'], 'database'],
            'synchronous queue' => [['queue.default' => 'sync'], 'workers'],
            'duplicate job reservation' => [['queue.connections.database.retry_after' => 60], 'workers'],
            'public Reverb listener' => [['reverb.servers.reverb.host' => '0.0.0.0'], 'reverb_listener'],
            'unrestricted Reverb origin' => [['reverb.apps.apps.0.allowed_origins' => ['*']], 'reverb_credentials'],
            'missing Reverb secret' => [['reverb.apps.apps.0.secret' => ''], 'reverb_credentials'],
            'mismatched publisher key' => [['broadcasting.connections.reverb.key' => 'different-key'], 'reverb_credentials'],
            'email enabled without credentials' => [['hris_mail.enabled' => true, 'mail.mailers.smtp.password' => null], 'email'],
            'plaintext email' => [['hris_mail.enabled' => true, 'mail.mailers.smtp.port' => 25], 'email'],
            'email URL override' => [['hris_mail.enabled' => true, 'mail.mailers.smtp.url' => 'smtp://invalid-secret-fixture'], 'email'],
            'Telegram enabled without credentials' => [['telegram.enabled' => true, 'telegram.bot_token' => null], 'telegram'],
            'remote files without backup support' => [['filesystems.default' => 's3'], 'filesystem'],
        ];
    }

    public function test_enabled_email_and_telegram_pass_configuration_checks_without_sending_messages(): void
    {
        $this->configureProduction();
        config(['hris_mail.enabled' => true, 'telegram.enabled' => true]);

        $this->assertSame(0, Artisan::call('hris:production-check', ['--json' => true]));

        $this->assertTrue(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['ready']);
        Http::assertNothingSent();
    }

    public function test_production_cors_advertises_only_the_public_frontend_even_for_a_localhost_request(): void
    {
        config(['cors' => $this->corsConfiguration('production')]);
        $this->assertSame(['https://hris.acme.ph'], config('cors.allowed_origins'));

        $this->withHeaders(['Origin' => 'http://localhost:5002', 'Access-Control-Request-Method' => 'POST'])
            ->options('/api/v1/auth/login')->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://hris.acme.ph');
        $this->withHeaders(['Origin' => 'https://hris.acme.ph', 'Access-Control-Request-Method' => 'POST'])
            ->options('/api/v1/auth/login')->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://hris.acme.ph')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_local_configuration_keeps_localhost_and_the_configured_frontend(): void
    {
        $this->assertSame(['https://hris.acme.ph', 'http://127.0.0.1:5002', 'http://localhost:5002'], $this->corsConfiguration('local')['allowed_origins']);
    }

    private function corsConfiguration(string $environment): array
    {
        $keys = ['APP_ENV' => $environment, 'FRONTEND_URL' => 'https://hris.acme.ph/'];
        $previous = [];
        foreach ($keys as $name => $value) {
            $previous[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv($name.'='.$value);
        }
        try {
            return require base_path('config/cors.php');
        } finally {
            foreach ($previous as $name => [$env, $server, $process]) {
                unset($_ENV[$name], $_SERVER[$name]);
                if ($env !== null) {
                    $_ENV[$name] = $env;
                }
                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }
                putenv($process === false ? $name : $name.'='.$process);
            }
        }
    }

    private function configureProduction(): void
    {
        config([
            'app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://hris.acme.ph',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'hris_mail.frontend_url' => 'https://hris.acme.ph', 'telegram.frontend_url' => 'https://hris.acme.ph',
            'cors.allowed_origins' => ['https://hris.acme.ph'], 'cors.allowed_origins_patterns' => [],
            'session.driver' => 'database', 'session.secure' => true, 'session.http_only' => true,
            'session.encrypt' => true, 'session.same_site' => 'lax', 'session.domain' => null,
            'database.default' => 'mysql', 'database.connections.mysql.url' => null,
            'database.connections.mysql.database' => 'hris', 'database.connections.mysql.username' => 'hris',
            'database.connections.mysql.password' => 'fixture-production-password-123',
            'queue.default' => 'database', 'queue.connections.database.retry_after' => 90, 'cache.default' => 'database',
            'broadcasting.default' => 'reverb', 'reverb.servers.reverb.host' => '127.0.0.1', 'reverb.servers.reverb.port' => 8082,
            'broadcasting.connections.reverb.app_id' => 'hris', 'broadcasting.connections.reverb.key' => str_repeat('k', 32),
            'broadcasting.connections.reverb.secret' => str_repeat('s', 40),
            'broadcasting.connections.reverb.options' => ['host' => '127.0.0.1', 'port' => 8082, 'scheme' => 'http'],
            'reverb.apps.apps.0' => ['app_id' => 'hris', 'key' => str_repeat('k', 32), 'secret' => str_repeat('s', 40),
                'allowed_origins' => ['hris.acme.ph'], 'accept_client_events_from' => 'none'],
            'hris_mail.enabled' => false, 'telegram.enabled' => false, 'filesystems.default' => 'local',
            'mail.default' => 'smtp', 'mail.mailers.smtp' => ['scheme' => 'smtps', 'port' => 465,
                'host' => 'smtp.acme.ph', 'username' => 'sender@acme.ph', 'password' => 'fixture-production-password-123'],
            'mail.from.address' => 'sender@acme.ph', 'telegram.bot_token' => str_repeat('t', 40),
            'telegram.bot_id' => 123456, 'telegram.bot_username' => 'hris_fixture_bot',
        ]);
    }
}
