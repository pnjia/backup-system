<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

        // Trailing slash ensures rclone copies the directory contents, not the directory itself.
        $localBackupRoot = rtrim(Storage::disk('backup')->path(''), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        $process = new Process(
            ['rclone', 'copy', $localBackupRoot, "{$remote}:{$remotePath}"],
            base_path()
        );
        $process->setTimeout(self::PROCESS_TIMEOUT);
        $process->run();

        if (! $process->isSuccessful()) {
            Log::channel('backup')->error(
                'Gagal mengupload backup ke remote rclone',
                ['error' => $process->getErrorOutput()]
            );
        }
    }
}
