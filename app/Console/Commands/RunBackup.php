<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class RunBackup extends Command
{
    /** @var int Timeout in seconds for each external process (30 minutes) */
    private const PROCESS_TIMEOUT = 1800;

    protected $signature = 'app:run-backup
        {--dry-run : Jalankan preflight saja, tanpa eksekusi backup}';

    protected $description = 'Backup database utama, database transaksi, dan folder uploads ke Google Drive via rclone';

    public function handle(): int
    {
        $this->info('0/6 Preflight checks');
        $this->runPreflightChecks();

        if ($this->option('dry-run')) {
            $this->warn('Dry-run aktif: hanya preflight, tidak ada backup yang dijalankan.');

            return self::SUCCESS;
        }

        $timestamp = now()->format('Y-m-d_H-i-s');
        $backupDir = storage_path('app/backup');
        $uploadsBackupDir = storage_path('app/backup/uploads');

        File::ensureDirectoryExists($backupDir);
        File::ensureDirectoryExists($uploadsBackupDir);

        $mainDbSql = $backupDir.DIRECTORY_SEPARATOR."mpos_{$timestamp}.sql";
        $transDbSql = $backupDir.DIRECTORY_SEPARATOR."mpos_transaction_{$timestamp}.sql";
        $uploadsTar = $uploadsBackupDir.DIRECTORY_SEPARATOR."uploads_{$timestamp}.tar.gz";

        $this->info('1/6 Backup database utama');
        $this->dumpDatabase($this->mainDbConfig(), $mainDbSql);
        $this->line('Tersimpan: '.$mainDbSql);

        $this->info('2/6 Backup database transaksi');
        $this->dumpDatabase($this->transactionDbConfig(), $transDbSql);
        $this->line('Tersimpan: '.$transDbSql);

        $this->info('3/6 Kompresi folder uploads');
        $this->compressUploads($uploadsTar);
        $this->line('Tersimpan: '.$uploadsTar);

        $this->info('4/6 Upload ke Google Drive via rclone');
        $this->uploadToRemote($backupDir);

        $this->info('5/6 Retention policy (hapus file lama)');
        $retentionDays = (int) config('backup-custom.retention_days', 7);
        $this->applyRetentionPolicy($backupDir, $retentionDays);

        $this->info('6/6 Backup selesai.');

        return self::SUCCESS;
    }

    private function mainDbConfig(): array
    {
        return (array) config('backup-custom.main_db', []);
    }

    private function transactionDbConfig(): array
    {
        return (array) config('backup-custom.transaction_db', []);
    }

    private function rcloneRemote(): string
    {
        return (string) config('backup-custom.rclone.remote', 'gdrive');
    }

    private function rcloneRemotePath(): string
    {
        return trim((string) config('backup-custom.rclone.remote_path', 'backup-app'), '/');
    }

    private function dumpDatabase(array $db, string $outputPath): void
    {
        $driver = (string) ($db['driver'] ?? '');

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $this->dumpMysql($db, $outputPath);

            return;
        }

        if ($driver === 'pgsql') {
            $this->dumpPgsql($db, $outputPath);

            return;
        }

        throw new RuntimeException(
            "Driver database '{$driver}' tidak didukung. Gunakan mysql, mariadb, atau pgsql."
        );
    }

    private function dumpMysql(array $db, string $outputPath): void
    {
        $database = (string) ($db['database'] ?? '');

        if ($database === '') {
            throw new RuntimeException('Nama database MySQL tidak boleh kosong.');
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

        $this->runProcessToFile($command, $outputPath, "Gagal dump MySQL database '{$database}'", $env);
    }

    private function dumpPgsql(array $db, string $outputPath): void
    {
        $database = (string) ($db['database'] ?? '');

        if ($database === '') {
            throw new RuntimeException('Nama database PostgreSQL tidak boleh kosong.');
        }

        $command = [
            'pg_dump',
            '--host='.(string) ($db['host'] ?? '127.0.0.1'),
            '--port='.(string) ($db['port'] ?? '5432'),
            '--username='.(string) ($db['username'] ?? 'postgres'),
            '--dbname='.$database,
            '--file='.$outputPath,
        ];

        $env = [];

        if (! empty($db['password'])) {
            $env['PGPASSWORD'] = (string) $db['password'];
        }

        $this->runProcess($command, "Gagal dump PostgreSQL database '{$database}'", $env);
    }

    private function compressUploads(string $outputPath): void
    {
        $uploadsDir = storage_path('app/public/uploads');

        if (! File::exists($uploadsDir)) {
            $this->warn("Folder uploads tidak ditemukan: {$uploadsDir}. Lewati kompresi.");

            return;
        }

        $this->runProcess(
            ['tar', '-czf', $outputPath, '-C', storage_path('app/public'), 'uploads'],
            'Gagal mengompresi folder uploads'
        );
    }

    private function uploadToRemote(string $backupDir): void
    {
        $remote = $this->rcloneRemote();
        $remotePath = $this->rcloneRemotePath();

        $this->runProcess(
            ['rclone', 'copy', $backupDir, "{$remote}:{$remotePath}"],
            'Gagal mengupload backup ke remote rclone'
        );

        $this->line("Upload selesai ke {$remote}:{$remotePath}.");
    }

    private function applyRetentionPolicy(string $backupDir, int $retentionDays): void
    {
        $cutoff = now()->subDays($retentionDays);
        $deletedCount = 0;

        foreach (File::allFiles($backupDir) as $file) {
            if ($file->getMTime() < $cutoff->timestamp) {
                File::delete($file->getPathname());
                $this->line('Hapus lokal: '.$file->getFilename());
                $deletedCount++;
            }
        }

        $this->line("Lokal: {$deletedCount} file lama dihapus.");

        $remote = $this->rcloneRemote();
        $remotePath = $this->rcloneRemotePath();
        $minAge = "{$retentionDays}d";

        $this->runProcess(
            ['rclone', 'delete', "{$remote}:{$remotePath}", '--min-age', $minAge, '--rmdirs'],
            'Gagal menghapus file lama dari remote rclone'
        );

        $this->line("Remote: file lebih dari {$retentionDays} hari dihapus dari {$remote}:{$remotePath}.");
    }

    private function runPreflightChecks(): void
    {
        $this->ensureBinaryAvailable('rclone');
        $this->ensureBinaryAvailable('tar');

        foreach ([
            (string) config('backup-custom.main_db.driver', 'mysql'),
            (string) config('backup-custom.transaction_db.driver', 'mysql'),
        ] as $driver) {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $this->ensureBinaryAvailable('mysqldump');
            } elseif ($driver === 'pgsql') {
                $this->ensureBinaryAvailable('pg_dump');
            } else {
                throw new RuntimeException(
                    "Driver '{$driver}' tidak didukung. Gunakan mysql, mariadb, atau pgsql."
                );
            }
        }

        $this->line('Semua dependency tersedia.');
    }

    private function runProcess(array $command, string $errorContext, array $env = []): void
    {
        $process = new Process($command, base_path(), $env);
        $process->setTimeout(self::PROCESS_TIMEOUT);
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
        $process->setTimeout(self::PROCESS_TIMEOUT);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException($errorContext.': '.$process->getErrorOutput());
        }

        if (File::put($outputPath, $process->getOutput()) === false) {
            throw new RuntimeException('Gagal menulis output ke file: '.$outputPath);
        }
    }

    private function ensureBinaryAvailable(string $binary): void
    {
        $finder = new ExecutableFinder;

        if ($finder->find($binary) === null) {
            throw new RuntimeException("Dependency '{$binary}' tidak ditemukan di PATH.");
        }
    }
}
