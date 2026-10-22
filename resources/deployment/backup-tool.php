<?php

/**
 * Standalone maintenance tool. Archives contain HR data and the encryption key:
 * keep mode 600 and encrypt before copying to an offsite backup destination.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Symfony\Component\Process\Process;

umask(0077);

class HrisBackupException extends RuntimeException {}

function backupFail(string $message): never
{
    throw new HrisBackupException($message);
}

function backupRemoveDirectory(string $directory): void
{
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $directory.DIRECTORY_SEPARATOR.$entry;
        if (is_dir($path) && ! is_link($path)) {
            backupRemoveDirectory($path);
        } else {
            unlink($path);
        }
    }
    rmdir($directory);
}

function backupApplication(string $root): Application
{
    $root = realpath($root);
    if ($root === false || ! is_file($root.'/artisan') || ! is_file($root.'/.env') || is_link($root.'/.env')) {
        backupFail('Use an existing backend with its own regular .env file.');
    }
    require_once $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->isProduction() || $app['config']->get('filesystems.default') !== 'local') {
        backupFail('This tool requires production configuration and local file storage.');
    }
    foreach (['local' => $root.'/storage/app/private', 'public' => $root.'/storage/app/public'] as $disk => $expected) {
        $actual = $app['config']->get('filesystems.disks.'.$disk.'.root');
        if (str_replace('\\', '/', (string) $actual) !== str_replace('\\', '/', $expected)) {
            backupFail('Custom disk roots need a separately reviewed backup plan.');
        }
    }

    return $app;
}

/** @return array{file: string, database: string} */
function backupMysqlOptions(Application $app, string $work): array
{
    $connection = $app['db']->connection();
    $config = $connection->getConfig();
    if (($config['driver'] ?? '') !== 'mysql'
        || preg_match('/\A[a-zA-Z0-9_]{1,64}\z/D', (string) ($config['database'] ?? '')) !== 1) {
        backupFail('Only a named MySQL/MariaDB database is supported.');
    }
    $options = "[client]\n";
    $values = ['host' => $config['host'] ?? '127.0.0.1', 'port' => $config['port'] ?? 3306,
        'user' => $config['username'] ?? '', 'password' => $config['password'] ?? '', 'default-character-set' => 'utf8mb4'];
    if (! empty($config['unix_socket'])) {
        $values['socket'] = $config['unix_socket'];
    }
    foreach ($values as $key => $value) {
        if (! is_scalar($value) || str_contains((string) $value, "\0")) {
            backupFail('Unsupported database connection configuration.');
        }
        $escaped = str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], (string) $value);
        $options .= $key.'="'.$escaped.'"'."\n";
    }
    $file = $work.'/mysql.cnf';
    file_put_contents($file, $options);
    chmod($file, 0600);

    return ['file' => $file, 'database' => $config['database']];
}

function backupNewDirectory(string $path): string
{
    if (file_exists($path) || is_link($path) || ! is_dir(dirname($path))) {
        backupFail('Choose a NEW directory with an existing parent.');
    }
    if (! mkdir($path, 0700)) {
        backupFail('Cannot create the private working directory.');
    }

    return realpath($path);
}

function backupSafeName(string $name): bool
{
    return ! str_contains($name, '\\') && ! str_contains($name, "\0")
        && ! str_contains($name, ':') && ! str_starts_with($name, '/')
        && preg_match('~(?:\A|/)(?:\.|\.\.)(?:/|\z)~D', $name) !== 1
        && ($name === '.env' || $name === 'database.sql' || str_starts_with($name, 'storage/'));
}

/** @return array{archive: PharData, manifest: array} */
function backupVerify(string $path): array
{
    if (! is_file($path) || is_link($path) || ! str_ends_with($path, '.tar.gz')) {
        backupFail('Choose a regular HRIS .tar.gz archive.');
    }
    $archive = new PharData($path);
    if (! isset($archive['manifest.json']) || ! $archive['manifest.json']->isFile()
        || $archive['manifest.json']->isLink() || $archive['manifest.json']->getSize() > 10_000_000) {
        backupFail('Missing or oversized manifest.');
    }
    $manifest = json_decode($archive['manifest.json']->getContent(), true, flags: JSON_THROW_ON_ERROR);
    if (($manifest['format'] ?? '') !== 'hris-backup-v1' || ! is_array($manifest['files'] ?? null)
        || ! isset($manifest['files']['.env'], $manifest['files']['database.sql'])
        || preg_match('/\A[a-zA-Z0-9_]{1,64}\z/D', (string) ($manifest['database'] ?? '')) !== 1) {
        backupFail('Invalid HRIS backup manifest.');
    }
    $seen = [];
    $prefix = 'phar://'.str_replace('\\', '/', $archive->getPath()).'/';
    foreach (new RecursiveIteratorIterator($archive) as $entry) {
        $name = substr(str_replace('\\', '/', $entry->getPathname()), strlen($prefix));
        if ($name === 'manifest.json') {
            continue;
        }
        if (! $entry->isFile() || $entry->isLink() || ! backupSafeName($name)
            || ! isset($manifest['files'][$name]) || isset($seen[$name])) {
            backupFail('Unsafe or unexpected archive member.');
        }
        $expected = $manifest['files'][$name];
        if (! is_array($expected) || ! is_int($expected['bytes'] ?? null)
            || $entry->getSize() !== $expected['bytes']
            || ! is_string($expected['sha256'] ?? null)
            || ! hash_equals($expected['sha256'], hash_file('sha256', $entry->getPathname()))) {
            backupFail('Backup checksum verification failed.');
        }
        $seen[$name] = true;
    }
    if (count($seen) !== count($manifest['files'])) {
        backupFail('Backup is missing manifest members.');
    }

    return ['archive' => $archive, 'manifest' => $manifest];
}

$action = $argv[1] ?? '--help';
$work = null;
$dumpHandle = null;
$status = 0;
$retainStaging = false;
try {
    if ($action === '--help') {
        echo <<<'HELP'
Usage:
  backup.sh BACKEND EXISTING_PRIVATE_BACKUP_DIR --quiesced
  restore.sh verify ARCHIVE.tar.gz
  restore.sh restore ARCHIVE.tar.gz TARGET_BACKEND NEW_STAGING_DIR --database=EMPTY_DB

Backup requires Laravel maintenance mode plus --quiesced confirmation that all
writers (PHP-FPM, scheduler, queue, Telegram, Reverb) are stopped. It refuses
non-InnoDB base tables. The archive includes .env, SQL and all storage/app files.
Keep it private and encrypt it before offsite transfer. No retention is applied.

Restore uses TARGET_BACKEND's production .env credentials. Its database must
exist, be completely EMPTY and differ from the source database name. SQL is
imported only there. Files and the original .env are staged separately; neither
live storage nor the target .env is replaced. Do not point a live app at the
target until you have reviewed and tested the restoration. If an import fails,
the partial new schema is left for review; no DROP or automatic retry is run.
HELP;
        echo PHP_EOL;
        exit(0);
    }
    if ($action === 'verify' && count($argv) === 3) {
        backupVerify($argv[2]);
        echo "PASS: all backup members and SHA-256 checksums verified.\n";
        exit(0);
    }
    if ($action === 'backup' && count($argv) === 5 && $argv[4] === '--quiesced') {
        $app = backupApplication($argv[2]);
        if (! $app->isDownForMaintenance()) {
            backupFail('Put the application into maintenance mode and stop all writers first.');
        }
        $destination = realpath($argv[3]);
        if ($destination === false || ! is_dir($destination) || ! is_writable($destination)
            || str_starts_with(str_replace('\\', '/', $destination).'/', str_replace('\\', '/', $app->basePath()).'/')) {
            backupFail('Use a writable private backup directory outside the application tree.');
        }
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($destination) & 0077) !== 0) {
            backupFail('The backup directory must be private (chmod 700) before storing HR data and secrets.');
        }
        $work = backupNewDirectory($destination.'/.hris-backup-'.bin2hex(random_bytes(12)));
        $mysql = backupMysqlOptions($app, $work);
        $connection = $app['db']->connection();
        $nonTransactional = $connection->select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' AND ENGINE <> 'InnoDB'", [$mysql['database']]);
        if ($nonTransactional !== []) {
            backupFail('Non-InnoDB tables require a separately reviewed backup strategy.');
        }
        $sql = $work.'/database.sql';
        $dump = new Process(['mysqldump', '--defaults-extra-file='.$mysql['file'], '--single-transaction', '--quick', '--skip-lock-tables', '--hex-blob', '--result-file='.$sql, $mysql['database']]);
        $dump->setTimeout(3600);
        $dump->disableOutput();
        if ($dump->run() !== 0 || ! is_file($sql) || filesize($sql) === 0) {
            backupFail('Database dump failed; no completed backup was published.');
        }
        $archivePath = $work.'/snapshot.tar';
        $archive = new PharData($archivePath);
        $files = [];
        $sources = ['.env' => $app->basePath('.env'), 'database.sql' => $sql];
        $storage = $app->storagePath('app');
        if (! is_dir($storage) || is_link($storage)) {
            backupFail('Expected a regular storage/app directory.');
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink() || ! $file->isFile()) {
                backupFail('Storage symlinks or special files require a separate backup strategy.');
            }
            $name = 'storage/'.str_replace('\\', '/', substr($file->getPathname(), strlen($storage) + 1));
            $sources[$name] = $file->getPathname();
        }
        foreach ($sources as $name => $path) {
            if (! backupSafeName($name) || is_link($path)) {
                backupFail('Unsafe backup file.');
            }
            $archive->addFile($path, $name);
            $files[$name] = ['bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
        }
        $archive->addFromString('manifest.json', json_encode(['format' => 'hris-backup-v1', 'created_at' => gmdate(DATE_ATOM), 'database' => $mysql['database'], 'files' => $files], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $archive->compress(Phar::GZ);
        unset($archive);
        backupVerify($archivePath.'.gz');
        $final = $destination.'/hris-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.tar.gz';
        if (! rename($archivePath.'.gz', $final)) {
            backupFail('Could not publish the completed backup.');
        }
        chmod($final, 0600);
        echo 'PASS: private verified backup created at '.$final.PHP_EOL;
    } elseif ($action === 'restore' && count($argv) === 6 && str_starts_with($argv[5], '--database=')) {
        $verified = backupVerify($argv[2]);
        $app = backupApplication($argv[3]);
        $database = substr($argv[5], strlen('--database='));
        if (preg_match('/\A[a-zA-Z0-9_]{1,64}\z/D', $database) !== 1 || $database === $verified['manifest']['database']) {
            backupFail('Confirm a differently named empty recovery database.');
        }
        $connection = $app['db']->connection();
        if ($connection->getDatabaseName() !== $database) {
            backupFail('The explicit database must match the target backend configuration.');
        }
        $tables = $connection->select('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$database]);
        if ($tables !== [] || $connection->selectOne('SELECT DATABASE() AS db')->db !== $database) {
            backupFail('Restore requires an existing completely empty database. Nothing was imported.');
        }
        $work = backupNewDirectory($argv[4]);
        foreach ($verified['manifest']['files'] as $name => $metadata) {
            $target = $work.'/'.$name;
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            $input = $verified['archive'][$name]->openFile('r');
            $output = fopen($target, 'xb');
            while (! $input->eof()) {
                fwrite($output, $input->fread(1024 * 1024));
            }
            fclose($output);
            chmod($target, 0600);
        }
        $mysql = backupMysqlOptions($app, $work);
        $retainStaging = true;
        $dumpHandle = fopen($work.'/database.sql', 'rb');
        $restore = new Process(['mysql', '--defaults-extra-file='.$mysql['file'], '--binary-mode=1', $database]);
        $restore->setInput($dumpHandle);
        $restore->setTimeout(3600);
        $restore->disableOutput();
        $exit = $restore->run();
        fclose($dumpHandle);
        $dumpHandle = null;
        unlink($mysql['file']);
        $staging = $work;
        $work = null;
        if ($exit !== 0) {
            backupFail('Import failed. The new schema and private staging files remain for review; do not retry against a nonempty schema.');
        }
        echo 'PASS: imported the empty recovery schema; files staged at '.$staging.'. Review before activation.'.PHP_EOL;
    } else {
        backupFail('Invalid arguments. Run backup-tool.php --help.');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, ($exception instanceof HrisBackupException ? $exception->getMessage() : 'Backup/restore failed ('.get_debug_type($exception).'). Inspect the private target locally; connection details are suppressed.').PHP_EOL);
    $status = 1;
} finally {
    if (is_resource($dumpHandle)) {
        fclose($dumpHandle);
    }
    if ($work !== null && is_dir($work) && ! is_link($work)) {
        if ($retainStaging) {
            if (is_file($work.'/mysql.cnf')) {
                unlink($work.'/mysql.cnf');
            }
        } else {
            backupRemoveDirectory($work);
        }
    }
}
exit($status);
