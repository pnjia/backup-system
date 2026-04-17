<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database Utama
    |--------------------------------------------------------------------------
    |
    | Konfigurasi koneksi untuk dump database utama (mpos).
    |
    */
    'main_db' => [
        'driver' => env('BACKUP_MAIN_DB_CONNECTION', 'mysql'),
        'host' => env('BACKUP_MAIN_DB_HOST', env('DB_HOST', '127.0.0.1')),
        'port' => env('BACKUP_MAIN_DB_PORT', env('DB_PORT', '3306')),
        'database' => env('BACKUP_MAIN_DB_DATABASE', 'mpos'),
        'username' => env('BACKUP_MAIN_DB_USERNAME', env('DB_USERNAME', 'root')),
        'password' => env('BACKUP_MAIN_DB_PASSWORD', env('DB_PASSWORD', '')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Transaksi
    |--------------------------------------------------------------------------
    |
    | Konfigurasi koneksi untuk dump database transaksi (mpos_transaction).
    |
    */
    'transaction_db' => [
        'driver' => env('BACKUP_TRANSACTION_DB_CONNECTION', 'mysql'),
        'host' => env('BACKUP_TRANSACTION_DB_HOST', env('DB_HOST', '127.0.0.1')),
        'port' => env('BACKUP_TRANSACTION_DB_PORT', env('DB_PORT', '3306')),
        'database' => env('BACKUP_TRANSACTION_DB_DATABASE', 'mpos_transaction'),
        'username' => env('BACKUP_TRANSACTION_DB_USERNAME', env('DB_USERNAME', 'root')),
        'password' => env('BACKUP_TRANSACTION_DB_PASSWORD', env('DB_PASSWORD', '')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rclone
    |--------------------------------------------------------------------------
    |
    | Nama remote rclone dan path tujuan di remote tersebut.
    |
    */
    'rclone' => [
        'remote' => env('RCLONE_REMOTE', 'gdrive'),
        'remote_path' => env('RCLONE_REMOTE_PATH', 'backup-app'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention Policy
    |--------------------------------------------------------------------------
    |
    | Jumlah hari untuk menyimpan backup. File lebih lama dari nilai ini
    | akan dihapus, baik secara lokal maupun di remote.
    |
    */
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 7),

];
