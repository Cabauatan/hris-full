<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TestEnvironmentIsolationTest extends TestCase
{
    public function test_uses_memory_database_and_test_only_configuration_and_route_cache_paths(): void
    {
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertNull(config('database.connections.sqlite.url'));
        $this->assertSame('array', config('mail.default'));
        $this->assertFalse(config('hris_mail.enabled'));
        $this->assertFalse(config('telegram.enabled', false));
        $this->assertSame(base_path('storage/framework/testing/config.php'), $this->app->getCachedConfigPath());
        $this->assertSame(base_path('storage/framework/testing/routes.php'), $this->app->getCachedRoutesPath());
    }

    public function test_configured_runner_overrides_inherited_live_settings_and_ignores_their_cache(): void
    {
        $cachePath = storage_path('framework/testing/phase14-poison-config-'.Str::uuid().'.php');
        if (! is_dir(dirname($cachePath))) {
            mkdir(dirname($cachePath), 0777, true);
        }
        file_put_contents($cachePath, '<?php throw new RuntimeException("Live config cache must not be loaded by tests.");');

        try {
            $process = $this->runner(['--configuration=phpunit.xml'], [
                'APP_ENV' => 'production',
                'APP_CONFIG_CACHE' => $cachePath,
                'APP_ROUTES_CACHE' => $cachePath,
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => 'phase14_forbidden_database',
                'DB_URL' => 'mysql://fixture:fixture@127.0.0.1/phase14_forbidden_database',
                'CACHE_STORE' => 'database',
                'QUEUE_CONNECTION' => 'database',
                'SESSION_DRIVER' => 'database',
                'MAIL_MAILER' => 'smtp',
            ]);

            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $this->assertFileExists($cachePath);
        } finally {
            unlink($cachePath);
        }

        $this->assertFileDoesNotExist($cachePath);
    }

    #[DataProvider('unisolatedEnvironments')]
    public function test_unconfigured_runner_refuses_unsafe_environment_before_running_a_feature(array $environment): void
    {
        $process = $this->runner(['--no-configuration', '--bootstrap=vendor/autoload.php'], $environment);

        $this->assertSame(2, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('Tests require the testing environment and an isolated SQLite :memory: database', $process->getOutput().$process->getErrorOutput());
    }

    public static function unisolatedEnvironments(): array
    {
        return [
            'live environment' => [['APP_ENV' => 'production']],
            'live connection' => [['DB_CONNECTION' => 'mysql']],
            'persistent database file' => [['DB_DATABASE' => 'phase14_forbidden_database.sqlite']],
            'database URL' => [['DB_URL' => 'mysql://fixture:fixture@127.0.0.1/phase14_forbidden_database']],
            'zero database URL' => [['DB_URL' => '0']],
            'whitespace database URL' => [['DB_URL' => '   ']],
        ];
    }

    private function runner(array $arguments, array $environment): Process
    {
        $process = new Process([
            PHP_BINARY, 'vendor/bin/phpunit', ...$arguments,
            '--colors=never', '--do-not-cache-result', '--no-logging',
            'tests/Feature/ExampleTest.php',
        ], base_path(), array_replace([
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => storage_path('framework/testing/phase14-unused-config.php'),
            'APP_ROUTES_CACHE' => storage_path('framework/testing/phase14-unused-routes.php'),
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
        ], $environment));
        $process->setTimeout(30);
        $process->run();

        return $process;
    }
}
