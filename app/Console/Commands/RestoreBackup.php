<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

class RestoreBackup extends Command
{
    protected $signature = 'restore:backup
        {name : Nama file backup zip di remote, contoh: backup.zip}
        {--disk=gdrive : Nama remote rclone}
        {--remote-path=backup-app : Folder backup di remote}
        {--local-dir= : Folder lokal untuk menyimpan file zip hasil download}
        {--extract-dir= : Folder lokal untuk ekstraksi backup}
        {--db-file=database.sql : Nama file dump DB di dalam zip}
        {--restore-db=1 : 1 untuk restore DB, 0 untuk skip}
        {--restore-files=1 : 1 untuk restore file storage, 0 untuk skip}
        {--rollback-on-fail : Rollback otomatis jika restore gagal}
        {--snapshot-dir= : Folder untuk menyimpan snapshot pra-restore}
        {--dry-run : Download + extract + validasi saja, tanpa restore}
        {--force : Lewati konfirmasi sebelum restore}';

    protected $description = 'Download backup zip dari rclone lalu restore database dan file storage';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        $disk = (string) $this->option('disk');
        $remotePath = trim((string) $this->option('remote-path'), '/');
        $restoreDb = $this->isTruthyOption('restore-db');
        $restoreFiles = $this->isTruthyOption('restore-files');
        $rollbackOnFail = (bool) $this->option('rollback-on-fail');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $this->info('0/5 Preflight checks');
        $this->runPreflightChecks($restoreDb, $rollbackOnFail);

        $localDir = $this->normalizePathOption((string) $this->option('local-dir'), storage_path('app/restore/downloads'));
        $extractDir = $this->normalizePathOption((string) $this->option('extract-dir'), storage_path('app/restore/extracted'));
        $snapshotRoot = $this->normalizePathOption((string) $this->option('snapshot-dir'), storage_path('app/restore/snapshots'));

        File::ensureDirectoryExists($localDir);
        File::ensureDirectoryExists($extractDir);

        $localZipPath = $localDir.DIRECTORY_SEPARATOR.$name;
        $runId = date('Ymd_His');
        $extractTarget = $extractDir.DIRECTORY_SEPARATOR.$runId;
        File::ensureDirectoryExists($extractTarget);

        $remoteFile = $disk.':'.$remotePath.'/'.$name;

        $this->info('1/5 Download backup dari remote');
        $this->line('Remote: '.$remoteFile);
        $this->line('Local : '.$localZipPath);
        $this->runProcess(['rclone', 'copyto', $remoteFile, $localZipPath], 'Gagal mengunduh backup dari remote');

        $this->info('2/5 Ekstrak file zip');
        $this->extractZip($localZipPath, $extractTarget);

        $this->info('3/5 Validasi isi backup');
        $dbDumpPath = $this->findFirstByFileName($extractTarget, (string) $this->option('db-file'));

        if ($restoreDb && $dbDumpPath === null) {
            throw new RuntimeException('File dump database tidak ditemukan. Set --db-file sesuai nama file dump di backup.');
        }

        if ($dbDumpPath !== null) {
            $this->line('DB dump ditemukan: '.$dbDumpPath);
        }

        $storageDirInBackup = $this->findFirstDirectoryNamed($extractTarget, 'storage');

        if ($restoreFiles && $storageDirInBackup === null) {
            throw new RuntimeException('Folder storage tidak ditemukan di backup. Gunakan --restore-files=0 jika memang tidak ingin restore file.');
        }

        if ($storageDirInBackup !== null) {
            $this->line('Folder storage ditemukan: '.$storageDirInBackup);
        }

        if ($dryRun) {
            $this->warn('Dry-run aktif: restore dilewati.');
            $this->line('Folder hasil ekstrak: '.$extractTarget);
            return self::SUCCESS;
        }

        if (! $force && ! $this->confirm('Restore akan menimpa data. Lanjutkan?', false)) {
            $this->warn('Restore dibatalkan oleh pengguna.');
            return self::INVALID;
        }

        $snapshotDbPath = null;
        $snapshotStoragePath = null;

        if ($rollbackOnFail) {
            $this->info('4/6 Siapkan snapshot pra-restore');
            $snapshotDir = $snapshotRoot.DIRECTORY_SEPARATOR.$runId;
            File::ensureDirectoryExists($snapshotDir);

            if ($restoreDb) {
                $snapshotDbPath = $snapshotDir.DIRECTORY_SEPARATOR.'database-before-restore.sql';
                $this->snapshotDatabase($snapshotDbPath);
                $this->line('Snapshot DB: '.$snapshotDbPath);
            }

            if ($restoreFiles) {
                $snapshotStoragePath = $snapshotDir.DIRECTORY_SEPARATOR.'storage-app-before-restore';
                $this->snapshotStorage($snapshotStoragePath);
                $this->line('Snapshot storage: '.$snapshotStoragePath);
            }
        }

        $this->info($rollbackOnFail ? '5/6 Jalankan maintenance mode' : '4/5 Jalankan maintenance mode');
        Artisan::call('down');
        $this->line(trim(Artisan::output()));

        try {
            try {
                if ($restoreDb && $dbDumpPath !== null) {
                    $this->info('Restore database dimulai');
                    $this->restoreDatabase($dbDumpPath);
                    $this->info('Restore database selesai');
                }

                if ($restoreFiles && $storageDirInBackup !== null) {
                    $this->info('Restore file storage dimulai');
                    $this->restoreStorage($storageDirInBackup);
                    $this->info('Restore file storage selesai');
                }
            } catch (Throwable $restoreError) {
                $this->error('Restore gagal: '.$restoreError->getMessage());

                if ($rollbackOnFail) {
                    $this->warn('Rollback otomatis dimulai...');

                    try {
                        $this->rollbackFromSnapshot($snapshotDbPath, $snapshotStoragePath, $restoreDb, $restoreFiles);
                        $this->info('Rollback otomatis selesai.');
                    } catch (Throwable $rollbackError) {
                        throw new RuntimeException(
                            'Restore gagal dan rollback juga gagal: '.$rollbackError->getMessage(),
                            0,
                            $restoreError
                        );
                    }
                }

                throw $restoreError;
            }
        } finally {
            $this->info($rollbackOnFail ? '6/6 Kembalikan aplikasi online' : '5/5 Kembalikan aplikasi online');
            Artisan::call('up');
            $this->line(trim(Artisan::output()));
        }

        $this->info('Restore backup selesai.');
        return self::SUCCESS;
    }

    private function normalizePathOption(string $value, string $fallback): string
    {
        $path = trim($value);

        if ($path === '') {
            return $fallback;
        }

        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:\\\\/', $path) === 1
            ? $path
            : base_path($path);
    }

    private function extractZip(string $zipPath, string $targetPath): void
    {
        if (! File::exists($zipPath)) {
            throw new RuntimeException('File zip tidak ditemukan di: '.$zipPath);
        }

        $zip = new ZipArchive();
        $status = $zip->open($zipPath);

        if ($status !== true) {
            throw new RuntimeException('Gagal membuka file zip backup: '.$zipPath);
        }

        if (! $zip->extractTo($targetPath)) {
            $zip->close();
            throw new RuntimeException('Gagal mengekstrak file zip ke: '.$targetPath);
        }

        $zip->close();
    }

    private function restoreDatabase(string $dbDumpPath): void
    {
        $connection = (string) config('database.default');
        $db = (array) config('database.connections.'.$connection, []);

        if ($connection === 'mysql') {
            $this->restoreMysql($db, $dbDumpPath);
            return;
        }

        if ($connection === 'pgsql') {
            $this->restorePgsql($db, $dbDumpPath);
            return;
        }

        throw new RuntimeException(
            'Restore DB otomatis hanya mendukung mysql/pgsql. Koneksi aktif: '.$connection
            .'. Jika hanya ingin restore file storage, gunakan --restore-db=0.'
        );
    }

    private function snapshotDatabase(string $snapshotDbPath): void
    {
        File::ensureDirectoryExists(dirname($snapshotDbPath));

        $connection = (string) config('database.default');
        $db = (array) config('database.connections.'.$connection, []);

        if ($connection === 'mysql') {
            $this->dumpMysql($db, $snapshotDbPath);
            return;
        }

        if ($connection === 'pgsql') {
            $this->dumpPgsql($db, $snapshotDbPath);
            return;
        }

        throw new RuntimeException(
            'Snapshot DB hanya mendukung mysql/pgsql. Koneksi aktif: '.$connection
            .'. Jika hanya ingin restore file storage, gunakan --restore-db=0.'
        );
    }

    private function restoreMysql(array $db, string $dbDumpPath): void
    {
        $database = (string) ($db['database'] ?? '');

        if ($database === '') {
            throw new RuntimeException('Konfigurasi database MySQL tidak valid. Nama database kosong.');
        }

        $command = [
            'mysql',
            '--host='.(string) ($db['host'] ?? '127.0.0.1'),
            '--port='.(string) ($db['port'] ?? '3306'),
            '--user='.(string) ($db['username'] ?? 'root'),
            $database,
        ];

        $env = [];

        if (! empty($db['password'])) {
            $env['MYSQL_PWD'] = (string) $db['password'];
        }

        $process = new Process($command, base_path(), $env);
        $handle = fopen($dbDumpPath, 'r');

        if ($handle === false) {
            throw new RuntimeException('Tidak bisa membaca dump DB: '.$dbDumpPath);
        }

        $process->setInput($handle);
        $process->setTimeout(1800);
        $process->run();
        fclose($handle);

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Restore MySQL gagal: '.$process->getErrorOutput());
        }
    }

    private function restorePgsql(array $db, string $dbDumpPath): void
    {
        $database = (string) ($db['database'] ?? '');

        if ($database === '') {
            throw new RuntimeException('Konfigurasi database PostgreSQL tidak valid. Nama database kosong.');
        }

        $command = [
            'psql',
            '--host='.(string) ($db['host'] ?? '127.0.0.1'),
            '--port='.(string) ($db['port'] ?? '5432'),
            '--username='.(string) ($db['username'] ?? 'postgres'),
            '--dbname='.$database,
            '--file='.$dbDumpPath,
        ];

        $env = [];

        if (! empty($db['password'])) {
            $env['PGPASSWORD'] = (string) $db['password'];
        }

        $this->runProcess($command, 'Restore PostgreSQL gagal', $env);
    }

    private function dumpMysql(array $db, string $snapshotDbPath): void
    {
        $database = (string) ($db['database'] ?? '');

        if ($database === '') {
            throw new RuntimeException('Konfigurasi database MySQL tidak valid. Nama database kosong.');
        }

        $command = [
            'mysqldump',
            '--host='.(string) ($db['host'] ?? '127.0.0.1'),
            '--port='.(string) ($db['port'] ?? '3306'),
            '--user='.(string) ($db['username'] ?? 'root'),
            '--single-transaction',
            '--routines',
            '--triggers',
            $database,
        ];

        $env = [];

        if (! empty($db['password'])) {
            $env['MYSQL_PWD'] = (string) $db['password'];
        }

        $this->runProcessToFile($command, $snapshotDbPath, 'Gagal membuat snapshot MySQL', $env);
    }

    private function dumpPgsql(array $db, string $snapshotDbPath): void
    {
        $database = (string) ($db['database'] ?? '');

        if ($database === '') {
            throw new RuntimeException('Konfigurasi database PostgreSQL tidak valid. Nama database kosong.');
        }

        $command = [
            'pg_dump',
            '--host='.(string) ($db['host'] ?? '127.0.0.1'),
            '--port='.(string) ($db['port'] ?? '5432'),
            '--username='.(string) ($db['username'] ?? 'postgres'),
            '--dbname='.$database,
            '--file='.$snapshotDbPath,
        ];

        $env = [];

        if (! empty($db['password'])) {
            $env['PGPASSWORD'] = (string) $db['password'];
        }

        $this->runProcess($command, 'Gagal membuat snapshot PostgreSQL', $env);
    }

    private function restoreStorage(string $storageDirInBackup): void
    {
        $source = rtrim($storageDirInBackup, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'app';

        if (! File::exists($source)) {
            throw new RuntimeException('Folder storage/app tidak ditemukan di backup: '.$source);
        }

        $target = storage_path('app');
        File::ensureDirectoryExists($target);

        $this->copyDirectoryContents($source, $target);
    }

    private function snapshotStorage(string $snapshotStoragePath): void
    {
        $source = storage_path('app');

        if (! File::exists($source)) {
            throw new RuntimeException('Folder storage/app tidak ditemukan untuk snapshot.');
        }

        File::deleteDirectory($snapshotStoragePath);

        if (! File::copyDirectory($source, $snapshotStoragePath)) {
            throw new RuntimeException('Gagal membuat snapshot storage ke: '.$snapshotStoragePath);
        }
    }

    private function rollbackFromSnapshot(
        ?string $snapshotDbPath,
        ?string $snapshotStoragePath,
        bool $restoreDb,
        bool $restoreFiles
    ): void {
        if ($restoreDb && $snapshotDbPath !== null) {
            if (! File::exists($snapshotDbPath)) {
                throw new RuntimeException('Snapshot DB tidak ditemukan: '.$snapshotDbPath);
            }

            $this->restoreDatabase($snapshotDbPath);
        }

        if ($restoreFiles && $snapshotStoragePath !== null) {
            if (! File::exists($snapshotStoragePath)) {
                throw new RuntimeException('Snapshot storage tidak ditemukan: '.$snapshotStoragePath);
            }

            $target = storage_path('app');
            File::deleteDirectory($target);

            if (! File::copyDirectory($snapshotStoragePath, $target)) {
                throw new RuntimeException('Rollback storage gagal dari: '.$snapshotStoragePath);
            }
        }
    }

    private function copyDirectoryContents(string $source, string $target): void
    {
        $entries = File::allFiles($source);

        foreach ($entries as $entry) {
            $relativePath = ltrim(str_replace($source, '', $entry->getPathname()), DIRECTORY_SEPARATOR);
            $destPath = $target.DIRECTORY_SEPARATOR.$relativePath;
            File::ensureDirectoryExists(dirname($destPath));
            File::copy($entry->getPathname(), $destPath);
        }
    }

    private function findFirstByFileName(string $rootPath, string $fileName): ?string
    {
        $name = trim($fileName);

        if ($name === '') {
            return null;
        }

        foreach (File::allFiles($rootPath) as $file) {
            if ($file->getFilename() === $name) {
                return $file->getPathname();
            }
        }

        return null;
    }

    private function findFirstDirectoryNamed(string $rootPath, string $dirName): ?string
    {
        $directories = File::directories($rootPath);

        foreach ($directories as $directory) {
            if (basename($directory) === $dirName) {
                return $directory;
            }

            $found = $this->findFirstDirectoryNamed($directory, $dirName);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function runProcess(array $command, string $errorContext, array $env = []): void
    {
        $process = new Process($command, base_path(), $env);
        $process->setTimeout(1800);
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        if (! $process->isSuccessful()) {
            throw new RuntimeException($errorContext.': '.$process->getErrorOutput());
        }
    }

    private function runProcessToFile(array $command, string $outputPath, string $errorContext, array $env = []): void
    {
        $process = new Process($command, base_path(), $env);
        $process->setTimeout(1800);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException($errorContext.': '.$process->getErrorOutput());
        }

        if (File::put($outputPath, $process->getOutput()) === false) {
            throw new RuntimeException('Gagal menulis output process ke file: '.$outputPath);
        }
    }

    private function runPreflightChecks(bool $restoreDb, bool $rollbackOnFail): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Ekstensi PHP zip tidak tersedia. Install ekstensi zip terlebih dahulu.');
        }

        $this->ensureBinaryAvailable('rclone');

        if (! $restoreDb) {
            return;
        }

        $connection = (string) config('database.default');

        if ($connection === 'mysql') {
            $this->ensureBinaryAvailable('mysql');

            if ($rollbackOnFail) {
                $this->ensureBinaryAvailable('mysqldump');
            }

            return;
        }

        if ($connection === 'pgsql') {
            $this->ensureBinaryAvailable('psql');

            if ($rollbackOnFail) {
                $this->ensureBinaryAvailable('pg_dump');
            }

            return;
        }

        throw new RuntimeException(
            'Preflight DB hanya mendukung mysql/pgsql. Koneksi aktif: '.$connection
            .'. Jika hanya ingin restore file storage, gunakan --restore-db=0.'
        );
    }

    private function ensureBinaryAvailable(string $binary): void
    {
        $finder = new ExecutableFinder();

        if ($finder->find($binary) === null) {
            throw new RuntimeException("Dependency '{$binary}' tidak ditemukan di PATH.");
        }
    }

    private function isTruthyOption(string $name): bool
    {
        $raw = (string) $this->option($name);

        return in_array(strtolower($raw), ['1', 'true', 'yes', 'y', 'on'], true);
    }
}
