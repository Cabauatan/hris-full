<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PDO;
use Phar;
use PharData;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeploymentBackupTest extends TestCase
{
    public function test_verification_accepts_a_complete_hashed_archive_without_extracting_private_files(): void
    {
        $directory = $this->directory();
        try {
            $archive = $this->archive($directory);

            $process = $this->runTool(['verify', $archive]);

            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString('all backup members and SHA-256 checksums verified', $process->getOutput());
            $this->assertFileDoesNotExist($directory.'/.env');
            $this->assertDirectoryDoesNotExist($directory.'/storage');
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_corrupt_payload_is_rejected_without_extracting_or_printing_the_secret(): void
    {
        $directory = $this->directory();
        try {
            $archive = $this->archive($directory, corrupt: true);

            $process = $this->runTool(['verify', $archive]);

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('checksum verification failed', $process->getErrorOutput());
            $this->assertStringNotContainsString('fixture-secret-value', $process->getOutput().$process->getErrorOutput());
            $this->assertFileDoesNotExist($directory.'/.env');
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_unexpected_archive_member_is_rejected_without_writing_outside_staging(): void
    {
        $directory = $this->directory();
        try {
            $archive = $this->archive($directory, extra: true);

            $process = $this->runTool(['verify', $archive]);

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('Unsafe or unexpected archive member', $process->getErrorOutput());
            $this->assertFileDoesNotExist($directory.'/escape.php');
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_backup_requires_explicit_quiescence_before_reading_application_configuration(): void
    {
        $process = $this->runTool(['backup', 'nonexistent-application', 'nonexistent-output']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Invalid arguments', $process->getErrorOutput());
    }

    public function test_restore_requires_an_explicit_database_confirmation(): void
    {
        $process = $this->runTool(['restore', 'nonexistent.tar.gz', 'nonexistent-application', 'nonexistent-output']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Invalid arguments', $process->getErrorOutput());
    }

    public function test_native_mysql_backup_restores_only_to_an_empty_separate_schema_and_stages_files(): void
    {
        $tools = getenv('HRIS_BACKUP_MYSQL_TEST_TOOLS');
        if (! is_string($tools) || ! is_file($tools.'/mysql_install_db.exe')) {
            $this->markTestSkipped('Opt-in Windows MariaDB rehearsal: set HRIS_BACKUP_MYSQL_TEST_TOOLS to the local tools directory.');
        }
        $directory = $this->directory();
        $server = null;
        $pdo = null;
        try {
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
            $this->assertIsResource($socket);
            $address = stream_socket_get_name($socket, false);
            $port = (int) substr($address, strrpos($address, ':') + 1);
            fclose($socket);
            $install = new Process([$tools.'/mysql_install_db.exe', '--datadir='.$directory.'/data', '--port='.$port, '--silent']);
            $install->setTimeout(60)->mustRun();
            $server = new Process([$tools.'/mysqld.exe', '--defaults-file='.$directory.'/data/my.ini', '--datadir='.$directory.'/data', '--port='.$port, '--bind-address=127.0.0.1', '--skip-log-bin', '--innodb-buffer-pool-size=32M']);
            $server->setTimeout(null)->start();
            $deadline = microtime(true) + 25;
            do {
                try {
                    $pdo = new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                } catch (\PDOException) {
                    usleep(100_000);
                }
            } while ($pdo === null && microtime(true) < $deadline && $server->isRunning());
            $this->assertInstanceOf(PDO::class, $pdo, 'The isolated MariaDB fixture must start.');
            $pdo->exec('CREATE DATABASE hris_backup_source CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo->exec('CREATE DATABASE hris_backup_recovery CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo->exec('CREATE TABLE hris_backup_source.fixture (id INT PRIMARY KEY, name VARCHAR(100), amount DECIMAL(12,2), payload BLOB, optional_value VARCHAR(30) NULL) ENGINE=InnoDB');
            $statement = $pdo->prepare('INSERT INTO hris_backup_source.fixture VALUES (1, ?, 25000.75, ?, NULL)');
            $statement->execute(['Synthetic ñ 日本語 🎂', "\0\xff\x01fixture"]);
            $statement = null;
            mkdir($directory.'/backups');
            $this->fixtureApplication($directory.'/source', $port, 'hris_backup_source');
            $this->fixtureApplication($directory.'/target', $port, 'hris_backup_recovery');
            $env = ['PATH' => $tools.PATH_SEPARATOR.getenv('PATH')];

            unlink($directory.'/source/storage/framework/down');
            $onlineBackup = $this->runTool(['backup', $directory.'/source', $directory.'/backups', '--quiesced'], $env);
            $this->assertSame(1, $onlineBackup->getExitCode());
            $this->assertStringContainsString('maintenance mode', $onlineBackup->getErrorOutput());
            file_put_contents($directory.'/source/storage/framework/down', '{}');
            $failedDump = $this->runTool(['backup', $directory.'/source', $directory.'/backups', '--quiesced'], ['PATH' => $directory]);
            $this->assertSame(1, $failedDump->getExitCode());
            $this->assertSame([], glob($directory.'/backups/.hris-backup-*'));
            $this->assertSame([], glob($directory.'/backups/*.tar.gz'));

            $backup = $this->runTool(['backup', $directory.'/source', $directory.'/backups', '--quiesced'], $env);

            $this->assertSame(0, $backup->getExitCode(), $backup->getErrorOutput());
            $archives = glob($directory.'/backups/*.tar.gz');
            $this->assertCount(1, $archives);
            $this->assertSame([], glob($directory.'/backups/.hris-backup-*'));
            $verified = $this->runTool(['verify', $archives[0]]);
            $this->assertSame(0, $verified->getExitCode(), $verified->getErrorOutput());

            $restore = $this->runTool(['restore', $archives[0], $directory.'/target', $directory.'/staging', '--database=hris_backup_recovery'], $env);

            $this->assertSame(0, $restore->getExitCode(), $restore->getErrorOutput());
            $sourceRows = $pdo->query('SELECT * FROM hris_backup_source.fixture')->fetchAll(PDO::FETCH_ASSOC);
            $this->assertSame($sourceRows, $pdo->query('SELECT * FROM hris_backup_recovery.fixture')->fetchAll(PDO::FETCH_ASSOC));
            $this->assertSame('Synthetic ñ 日本語 🎂', $sourceRows[0]['name']);
            $this->assertSame('25000.75', $sourceRows[0]['amount']);
            $this->assertSame("\0\xff\x01fixture", $sourceRows[0]['payload']);
            $this->assertNull($sourceRows[0]['optional_value']);
            $this->assertSame("fixture file ñ 🎂\0", file_get_contents($directory.'/staging/storage/private/document.bin'));
            $this->assertFileDoesNotExist($directory.'/staging/mysql.cnf');
            $this->assertSame('APP_NAME=fixture-target', file_get_contents($directory.'/target/.env'));

            $repeated = $this->runTool(['restore', $archives[0], $directory.'/target', $directory.'/second-staging', '--database=hris_backup_recovery'], $env);
            $this->assertSame(1, $repeated->getExitCode());
            $this->assertStringContainsString('completely empty database', $repeated->getErrorOutput());
            $this->assertDirectoryDoesNotExist($directory.'/second-staging');
            $sameSource = $this->runTool(['restore', $archives[0], $directory.'/source', $directory.'/bad-staging', '--database=hris_backup_source'], $env);
            $this->assertSame(1, $sameSource->getExitCode());
            $this->assertStringContainsString('differently named empty recovery database', $sameSource->getErrorOutput());
            $this->assertSame($sourceRows, $pdo->query('SELECT * FROM hris_backup_source.fixture')->fetchAll(PDO::FETCH_ASSOC));
        } finally {
            if ($pdo !== null) {
                try {
                    $pdo->exec('SHUTDOWN');
                } catch (\PDOException) {
                    // MariaDB may close the connection before acknowledging SHUTDOWN.
                }
                $pdo = null;
            }
            if ($server !== null) {
                $server->stop(10);
            }
            for ($attempt = 0; $attempt < 10 && is_dir($directory); $attempt++) {
                File::deleteDirectory($directory);
                clearstatcache(true, $directory);
                if (is_dir($directory)) {
                    usleep(200_000);
                }
            }
            $this->assertDirectoryDoesNotExist($directory);
        }
    }

    private function fixtureApplication(string $root, int $port, string $database): void
    {
        foreach (['vendor', 'bootstrap', 'storage/framework', 'storage/app/private', 'storage/app/public'] as $directory) {
            mkdir($root.'/'.$directory, 0700, true);
        }
        file_put_contents($root.'/artisan', '<?php');
        file_put_contents($root.'/.env', 'APP_NAME=fixture-'.basename($root));
        file_put_contents($root.'/storage/framework/down', '{}');
        file_put_contents($root.'/storage/app/private/document.bin', "fixture file ñ 🎂\0");
        file_put_contents($root.'/vendor/autoload.php', '<?php require_once '.var_export(base_path('vendor/autoload.php'), true).';');
        $config = ['app' => ['env' => 'production', 'maintenance' => ['driver' => 'file']],
            'filesystems' => ['default' => 'local', 'disks' => ['local' => ['root' => $root.'/storage/app/private'], 'public' => ['root' => $root.'/storage/app/public']]],
            'database' => ['default' => 'mysql', 'connections' => ['mysql' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => $port,
                'database' => $database, 'username' => 'root', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '']]]];
        $bootstrap = '<?php $app = new Illuminate\\Foundation\\Application('.var_export($root, true).');'
            .' $app->instance("env", "production"); $app->instance("config", new Illuminate\\Config\\Repository('.var_export($config, true).'));'
            .' $app->register(Illuminate\\Database\\DatabaseServiceProvider::class);'
            .' $app->singleton(Illuminate\\Contracts\\Foundation\\MaintenanceMode::class, Illuminate\\Foundation\\FileBasedMaintenanceMode::class);'
            .' $app->singleton(Illuminate\\Contracts\\Console\\Kernel::class, fn ($app) => new class($app, $app["events"]) extends Illuminate\\Foundation\\Console\\Kernel { public function bootstrap() {} });'
            .' return $app;';
        file_put_contents($root.'/bootstrap/app.php', $bootstrap);
    }

    private function directory(): string
    {
        $directory = storage_path('framework/testing/backup-test-'.bin2hex(random_bytes(12)));
        mkdir($directory, 0700, true);

        return $directory;
    }

    private function archive(string $directory, bool $corrupt = false, bool $extra = false): string
    {
        $path = $directory.'/fixture.tar';
        $archive = new PharData($path);
        $payloads = ['.env' => 'APP_KEY=fixture-secret-value', 'database.sql' => 'CREATE TABLE fixture (id INT);',
            'storage/private/employee.txt' => 'Unicode fixture: ñ 日本語 🎂'];
        $files = [];
        foreach ($payloads as $name => $content) {
            $files[$name] = ['bytes' => strlen($content), 'sha256' => hash('sha256', $content)];
            $archive->addFromString($name, $corrupt && $name === '.env' ? 'APP_KEY=fixture-corrupt-value' : $content);
        }
        if ($extra) {
            $archive->addFromString('escape.php', '<?php echo "unexpected";');
        }
        $archive->addFromString('manifest.json', json_encode(['format' => 'hris-backup-v1', 'database' => 'fixture_source', 'files' => $files], JSON_THROW_ON_ERROR));
        $archive->compress(Phar::GZ);

        return $path.'.gz';
    }

    private function runTool(array $arguments, array $environment = []): Process
    {
        $process = new Process([PHP_BINARY, base_path('resources/deployment/backup-tool.php'), ...$arguments], base_path(), $environment);
        $process->setTimeout(30);
        $process->run();

        return $process;
    }
}
