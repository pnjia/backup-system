# Backup System — Setup & Usage

Ringkasan langkah untuk menjalankan sistem backup ini (Laravel + Spatie Backup + rclone), termasuk alur restore otomatis dari Google Drive.

## 1. Persiapan environment
- Pastikan PHP (>= versi yang digunakan proyek) dan `composer` terpasang.
- Jalankan:

```bash
composer install
```

## 2. Variabel `.env` penting
- Atur koneksi disk di `config/filesystems.php` dan `.env` (`BACKUP_DISK`, `FILESYSTEM_DRIVER`, dsb.).
- Contoh variabel yang direkomendasikan:

```env
BACKUP_DELETE_OLD_BACKUPS_AFTER_DAYS=7
BACKUP_ARCHIVE_PASSWORD=             # optional
MAIL_FROM_ADDRESS=you@example.com    # untuk notifikasi
MAIL_FROM_NAME=AppName
```

## 3. Konfigurasi Spatie Backup
- File config utama: `config/backup.php`.
- Retensi yang setara dengan `delete_old_backups_after_days => 7` diterapkan pada `cleanup.default_strategy.keep_all_backups_for_days`.

## 4. Menjalankan backup dan cleanup manual
- Buat backup manual:

```bash
php artisan backup:run
```

- Membersihkan backup sesuai strategi:

```bash
php artisan backup:clean
```

## 5. Scheduler (production)
- Laravel scheduler perlu dipanggil tiap menit oleh cron (atau Task Scheduler di Windows):

```
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

- Di proyek ini, jadwal didefinisikan di `bootstrap/app.php` menggunakan `withSchedule(...)`.
- Contoh jadwal yang aktif saat ini:

```
$schedule->command('backup:run')->dailyAt('11:00');
$schedule->exec('rclone copy storage/app/private/Laravel gdrive:backup-app')->dailyAt('11:00');
```

- Catatan: jangan mendefinisikan jadwal yang sama di dua tempat sekaligus (`bootstrap/app.php` dan `routes/console.php`) karena akan membuat job jalan ganda.

Untuk menjalankan scheduler lokal saat development gunakan:

```bash
php artisan schedule:work
```

## 6. rclone
- Pastikan `rclone` dikonfigurasi dengan remote (mis. `gdrive`) dan uji manual:

```bash
rclone copy storage/app/private/Laravel gdrive:backup-app
```

## 7. Verifikasi
- Periksa folder tujuan (`storage/app` atau disk remote) apakah file zip dan dump database terbentuk.
- Cek log/timely notifications bila ada error.

## 8. Restore Backup Otomatis (Command Kustom)

Project ini menyediakan command kustom:

```bash
php artisan restore:backup <nama-file-zip>
```

Contoh:

```bash
php artisan restore:backup 2026-04-16-01-38-19.zip --disk=gdrive --remote-path=backup-app
```

### 8.1 Apa yang dilakukan command ini
1. Preflight check dependency (`rclone`, `zip`, dan binary DB jika diperlukan).
2. Download zip dari remote rclone.
3. Ekstrak ke folder temporary restore.
4. Validasi isi backup.
5. (Opsional) Masuk maintenance mode, restore DB dan file `storage/app`.
6. (Opsional) Rollback otomatis jika restore gagal.

### 8.2 Opsi utama
- `--dry-run`: hanya download + extract + validasi, tanpa restore data.
- `--restore-db=0`: skip restore database (hanya restore file storage).
- `--restore-files=0`: skip restore file storage (hanya restore database).
- `--rollback-on-fail`: ambil snapshot sebelum restore dan rollback otomatis jika gagal.
- `--snapshot-dir=...`: lokasi snapshot rollback.
- `--disk=...`: nama remote rclone (default `gdrive`).
- `--remote-path=...`: folder di remote (default `backup-app`).
- `--force`: lewati konfirmasi interaktif.

### 8.3 Contoh penggunaan umum

Dry-run aman:

```bash
php artisan restore:backup 2026-04-16-01-38-19.zip --dry-run --disk=gdrive --remote-path=backup-app -vv
```

Restore penuh:

```bash
php artisan restore:backup 2026-04-16-01-38-19.zip --force --disk=gdrive --remote-path=backup-app -vv
```

Restore hanya file storage:

```bash
php artisan restore:backup 2026-04-16-01-38-19.zip --force --restore-db=0 --disk=gdrive --remote-path=backup-app -vv
```

Restore dengan rollback otomatis:

```bash
php artisan restore:backup 2026-04-16-01-38-19.zip --force --rollback-on-fail --disk=gdrive --remote-path=backup-app -vv
```

### 8.4 Catatan untuk SQLite
- Restore DB otomatis saat ini hanya mendukung `mysql` dan `pgsql`.
- Jika environment menggunakan `sqlite`, gunakan `--restore-db=0` bila hanya ingin restore file storage.
- Contoh:

```bash
php artisan restore:backup 2026-04-16-01-38-19.zip --dry-run --restore-db=0 --disk=gdrive --remote-path=backup-app -vv
```

## 9. Referensi (Context7)
- Laravel Scheduler — penjelasan `schedule:run`, `schedule:work`, `onOneServer`, frekuensi schedule: lihat dokumentasi Laravel Scheduling: https://github.com/laravel/docs/blob/13.x/scheduling.md
- Spatie Laravel Backup — menjalankan `backup:run`, `backup:clean`, pengaturan `cleanup.default_strategy`: https://github.com/spatie/laravel-backup/blob/main/docs/installation-and-setup.md
- Laravel Artisan Commands (`withCommands`, command signature): https://github.com/laravel/docs/blob/13.x/artisan.md

---

## 10. `.env.example` & Windows Task Scheduler

- File `.env.example` (di root proyek) berisi variabel contoh untuk backup dan rclone. Sesuaikan nilainya sesuai lingkungan Anda.

- Contoh membuat Scheduled Task di Windows untuk menjalankan scheduler setiap menit (jalankan PowerShell sebagai Administrator dan sesuaikan path):

```powershell
schtasks /Create /SC MINUTE /MO 1 /TN "LaravelSchedule" /TR "C:\\php\\php.exe -d memory_limit=-1 -f C:\\path\\to\\project\\artisan schedule:run" /F
```

- Anda juga bisa membuat task yang menjalankan `rclone` setelah backup, atau panggil `backup:run` dan `rclone` melalui `schedule` di aplikasi (seperti contoh di `bootstrap/app.php`).

## 11. Copilot Prompt & Skill (Opsional)

Project ini sudah punya customization agar operasional backup/restore lebih konsisten:

- File instruction khusus backup/restore: `.github/instructions/backup.instructions.md`
- Prompt siap pakai restore insiden: `.github/prompts/restore-playbook.prompt.md`
- Skill workflow backup ops: `.github/skills/backup-ops/SKILL.md`

Contoh penggunaan cepat di Copilot Chat:

1. Jalankan prompt `/Restore Playbook` lalu isi nama file backup (contoh: `2026-04-16-01-38-19.zip`).
2. Ikuti output berurutan: `Preflight` -> `Dry Run` -> `Full Restore`.
3. Jika environment SQLite, gunakan varian `--restore-db=0` seperti yang disarankan prompt.

Contoh prompt chat manual (tanpa slash command):

```text
Jalankan playbook restore untuk backup 2026-04-16-01-38-19.zip.
Mulai dari dry-run, lalu berikan command full restore dengan rollback-on-fail.
```

---

Update: saya menambahkan contoh `.env.example` ke repository; lihat file root `.env.example`.

---

## 12. Custom Backup Command: `app:run-backup`

Command ini melakukan backup multi-database dan kompresi uploads secara manual maupun terjadwal
setiap jam, **tanpa bergantung pada Spatie Backup**.

### 12.1 Output yang dihasilkan

```
storage/app/backup/
├── mpos_[timestamp].sql                      # dump database utama
├── mpos_transaction_[timestamp].sql          # dump database transaksi
└── uploads/
    └── uploads_[timestamp].tar.gz            # kompresi folder storage/app/public/uploads
```

`[timestamp]` menggunakan format `Y-m-d_H-i-s` dan konsisten untuk satu kali jalan.

### 12.2 Konfigurasi `.env`

Tambahkan blok berikut ke `.env` (contoh tersedia di `.env.example`):

```env
# Database Utama
BACKUP_MAIN_DB_CONNECTION=mysql
BACKUP_MAIN_DB_HOST=127.0.0.1
BACKUP_MAIN_DB_PORT=3306
BACKUP_MAIN_DB_DATABASE=mpos
BACKUP_MAIN_DB_USERNAME=root
BACKUP_MAIN_DB_PASSWORD=your_password

# Database Transaksi
BACKUP_TRANSACTION_DB_CONNECTION=mysql
BACKUP_TRANSACTION_DB_HOST=127.0.0.1
BACKUP_TRANSACTION_DB_PORT=3306
BACKUP_TRANSACTION_DB_DATABASE=mpos_transaction
BACKUP_TRANSACTION_DB_USERNAME=root
BACKUP_TRANSACTION_DB_PASSWORD=your_password

# rclone
RCLONE_REMOTE=gdrive
RCLONE_REMOTE_PATH=backup-app

# Retention (hapus backup lebih dari N hari)
BACKUP_RETENTION_DAYS=7
```

### 12.3 Menjalankan backup manual

```bash
php artisan app:run-backup
```

Dry-run (preflight saja, tidak ada backup):

```bash
php artisan app:run-backup --dry-run
```

### 12.4 Jadwal otomatis (Hourly)

Command sudah didaftarkan di scheduler Laravel (`bootstrap/app.php`):

```php
$schedule->command('app:run-backup')->hourly();
```

Pastikan cron job sudah aktif di server (jalankan sekali sebagai root/user yang menjalankan PHP):

```
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

Untuk Windows, gunakan Task Scheduler (jalankan setiap menit seperti pada bagian 10).

### 12.5 Retention Policy

- **Lokal**: file di `storage/app/backup/` dengan `mtime` lebih lama dari `BACKUP_RETENTION_DAYS` hari
  akan dihapus otomatis setelah setiap backup.
- **Remote**: `rclone delete gdrive:backup-app --min-age 7d --rmdirs` dijalankan untuk membersihkan
  file lama di Google Drive.

### 12.6 Dependency yang dibutuhkan

| Binary | Kegunaan |
|--------|----------|
| `rclone` | Upload ke Google Drive & hapus file lama remote |
| `mysqldump` | Dump MySQL/MariaDB |
| `pg_dump` | Dump PostgreSQL (jika menggunakan pgsql) |
| `tar` | Kompresi folder uploads |

Pastikan semua binary tersedia di PATH sebelum menjalankan command.

