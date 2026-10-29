# Sistem Checklist Kerjaan

Checklist tugas harian pegawai eL Hotel & Resort Sanur. Login memakai username + PIN Sistem Absensi.

## Pasang

```bash
composer install
cp .env.example .env && php artisan key:generate
# isi DB_*, ATTENDANCE_API_URL, ATTENDANCE_API_TOKEN (= TASKS_API_TOKEN di Sistem Absensi), ATTENDANCE_PIN_HELP_URL
php artisan migrate
php artisan attendance:sync
php artisan checklist:import-workbook "eL Hotel Sanur Checklist.xlsx"
php artisan checklist:grant <username-admin> admin
```

Jadwalkan `php artisan schedule:run` setiap menit (sinkron pegawai tiap jam). Produksi: HTTPS dan `SESSION_SECURE_COOKIE=true`.

Catatan lengkap untuk pengembang: `CLAUDE.md`.
