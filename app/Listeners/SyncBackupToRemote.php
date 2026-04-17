<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Backup\Events\BackupWasSuccessful;
use Symfony\Component\Process\Process;

class SyncBackupToRemote
{
    /** @var int Timeout in seconds for the rclone process (30 minutes) */
    private const PROCESS_TIMEOUT = 1800;

    public function handle(BackupWasSuccessful $event): void
    {
        $remote = (string) config('backup-custom.rclone.remote', 'gdrive');
        $remotePath = trim((string) config('backup-custom.rclone.remote_path', 'backup-app'), '/');
        $localBackupRoot = Storage::disk('backup')->path('');

        $process = new Process(
            ['rclone', 'copy', $localBackupRoot, "{$remote}:{$remotePath}"],
            base_path()
        );
        $process->setTimeout(self::PROCESS_TIMEOUT);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'Gagal mengupload backup ke remote rclone: '.$process->getErrorOutput()
            );
        }
    }
}
