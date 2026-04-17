<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rclone
    |--------------------------------------------------------------------------
    |
    | Nama remote rclone dan path tujuan di remote tersebut.
    | Digunakan untuk mensinkronisasi backup lokal ke Google Drive setelah
    | backup selesai dibuat oleh spatie/laravel-backup.
    |
    */
    'rclone' => [
        'remote' => env('RCLONE_REMOTE', 'gdrive'),
        'remote_path' => env('RCLONE_REMOTE_PATH', 'backup-app'),
    ],

];
