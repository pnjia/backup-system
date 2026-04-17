# Backup System — Setup & Usage

Ringkasan langkah untuk menjalankan sistem backup ini (Laravel + Spatie Backup + rclone), termasuk alur restore otomatis dari Google Drive.

## 1. Persiapan environment
- Pastikan PHP (>= versi yang digunakan proyek) dan `composer` terpasang.
- Jalankan:

```bash
composer install
```

## 2. Variabel `.env` penting
- Atur konfigurasi backup di `config/backup.php` dan `.env`.
- Contoh variabel yang direkomendasikan:

```env
BACKUP_DELETE_OLD_BACKUPS_AFTER_DAYS=7
BACKUP_ARCHIVE_PASSWORD=             # optional
BACKUP_NOTIFICATION_MAIL=you@example.com
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

- Di proyek ini, jadwal didefinisikan di `routes/console.php`:

```php
Schedule::command('backup:run')->dailyAt('02:00');
Schedule::command('backup:clean')->dailyAt('03:00');
```

- `backup:run` membuat backup otomatis setiap hari pukul 02:00. Setelah backup selesai, event `BackupWasSuccessful` akan memicu `SyncBackupToRemote` listener yang mengupload backup ke Google Drive via rclone.
- `backup:clean` membersihkan backup lama sesuai strategi retensi setiap hari pukul 03:00.

Untuk menjalankan scheduler lokal saat development gunakan:

```bash
php artisan schedule:work
```

## 6. rclone
- Pastikan `rclone` dikonfigurasi dengan remote (mis. `gdrive`) dan uji manual:

```bash
rclone copy storage/app/backups/ gdrive:backup-app
```

- Upload ke Google Drive dilakukan otomatis oleh `App\Listeners\SyncBackupToRemote` setiap kali `backup:run` berhasil.
- Konfigurasi remote dan path ada di `.env`: `RCLONE_REMOTE` dan `RCLONE_REMOTE_PATH`.

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

- Anda juga bisa memanggil `backup:run` melalui `schedule` di `routes/console.php` seperti yang sudah dikonfigurasi.

## 12. Arsitektur Backup (Spatie Laravel Backup)

Proyek ini menggunakan `spatie/laravel-backup` sebagai mekanisme backup utama. Alur lengkapnya:

```
cron (setiap menit) → schedule:run
  → 02:00 backup:run
      ├── dump mysql_main  (mpos)          → storage/app/backups/{APP_NAME}/
      ├── dump mysql_transaction           → storage/app/backups/{APP_NAME}/
      ├── compress storage/app/public/     → (masuk ke dalam zip)
      └── zip semua → storage/app/backups/{APP_NAME}/{timestamp}.zip
          └── event BackupWasSuccessful
              └── SyncBackupToRemote listener
                  └── rclone copy storage/app/backups/ gdrive:backup-app
  → 03:00 backup:clean
      └── hapus backup lama sesuai BACKUP_DELETE_OLD_BACKUPS_AFTER_DAYS
```

### 12.1 Konfigurasi `.env` yang dibutuhkan

```env
# Database connections untuk backup
BACKUP_MAIN_DB_CONNECTION=mysql
BACKUP_MAIN_DB_HOST=127.0.0.1
BACKUP_MAIN_DB_PORT=3306
BACKUP_MAIN_DB_DATABASE=mpos
BACKUP_MAIN_DB_USERNAME=root
BACKUP_MAIN_DB_PASSWORD=your_password

BACKUP_TRANSACTION_DB_CONNECTION=mysql
BACKUP_TRANSACTION_DB_HOST=127.0.0.1
BACKUP_TRANSACTION_DB_PORT=3306
BACKUP_TRANSACTION_DB_DATABASE=mpos_transaction
BACKUP_TRANSACTION_DB_USERNAME=root
BACKUP_TRANSACTION_DB_PASSWORD=your_password

# rclone — untuk upload ke Google Drive
RCLONE_REMOTE=gdrive
RCLONE_REMOTE_PATH=backup-app

# Notifikasi email jika backup gagal
BACKUP_NOTIFICATION_MAIL=your@example.com
```

### 12.2 Menjalankan backup manual

```bash
php artisan backup:run
```

Membersihkan backup lama:

```bash
php artisan backup:clean
```

Memonitor kesehatan backup:

```bash
php artisan backup:monitor
```

### 12.3 Dependency yang dibutuhkan

| Binary | Kegunaan |
|--------|----------|
| `rclone` | Upload ke Google Drive |
| `mysqldump` | Dump MySQL/MariaDB |
| `pg_dump` | Dump PostgreSQL (jika menggunakan pgsql) |

Pastikan semua binary tersedia di PATH sebelum menjalankan backup.

