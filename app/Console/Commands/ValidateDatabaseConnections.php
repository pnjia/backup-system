<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ValidateDatabaseConnections extends Command
{
    protected $signature = 'db:validate-connections
        {--connections= : Koneksi yang ingin divalidasi, pisahkan koma (default: mysql_main,mysql_transaction)}';

    protected $description = 'Validasi koneksi dua database perpustakaan sebelum backup dijalankan';

    public function handle(): int
    {
        $option = trim((string) $this->option('connections'));

        $connections = $option !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $option))))
            : ['mysql_main', 'mysql_transaction'];

        $allPassed = true;

        foreach ($connections as $connection) {
            try {
                DB::connection($connection)->getPdo();
                $database = (string) config("database.connections.{$connection}.database", '-');
                $this->info("✓ [{$connection}] Koneksi berhasil (database: {$database})");
            } catch (Throwable $e) {
                $this->error("✗ [{$connection}] Koneksi gagal: {$e->getMessage()}");
                $allPassed = false;
            }
        }

        if (! $allPassed) {
            $this->newLine();
            $this->warn('Satu atau lebih koneksi database gagal. Periksa konfigurasi .env sebelum menjalankan backup.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Semua koneksi database berhasil divalidasi.');
        return self::SUCCESS;
    }
}
