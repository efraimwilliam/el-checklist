#!/usr/bin/env bash
# Update Sistem Checklist Kerjaan ke versi terbaru dari GitHub.
# Pakai (sebagai akun biasa, tanpa sudo di depannya):  checklist-update.sh [--paksa]
#   --paksa  tetap menjalankan migrasi dan cache walau kode sudah versi terbaru.
# Selama update, pengunjung melihat halaman "sedang diperbarui". Bila ada langkah yang gagal, aplikasi dibuka lagi.
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/checklist}"
WEB_USER="${WEB_USER:-www-data}"

if [ "$(id -u)" -eq 0 ]; then
    echo "Jalankan tanpa sudo: git memakai kunci GitHub akun Anda, dan perintah yang butuh hak lebih memanggil sudo sendiri." >&2
    exit 1
fi

FORCE=0
if [ "${1:-}" = "--paksa" ]; then
    FORCE=1
fi

cd "$APP_DIR"
# artisan selalu sebagai pengguna web, supaya file cache dan log tidak jadi milik akun lain.
artisan() { sudo -u "$WEB_USER" php artisan "$@"; }
FPM_SERVICE="php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "Ada perubahan lokal di $APP_DIR, update dibatalkan:" >&2
    git status --short --untracked-files=no >&2
    exit 1
fi

BRANCH="$(git rev-parse --abbrev-ref HEAD)"
OLD="$(git rev-parse --short HEAD)"
git fetch --quiet origin "$BRANCH"

if [ "$(git rev-parse HEAD)" = "$(git rev-parse "origin/$BRANCH")" ] && [ "$FORCE" -eq 0 ]; then
    echo "Sudah versi terbaru ($OLD). Tidak ada yang diubah. (Pakai --paksa untuk tetap menjalankan migrasi dan cache.)"
    exit 0
fi

DOWN=0
trap 'status=$?; if [ "$DOWN" -eq 1 ]; then artisan up || true; fi; echo "=== Update GAGAL (kode $status). Baca pesan error di atas. ===" >&2' ERR

echo ">> Mode pemeliharaan"
artisan down --render="errors::503" --retry=15 --refresh=15
DOWN=1

echo ">> Mengambil kode ($BRANCH)"
git pull --ff-only --quiet origin "$BRANCH"
NEW="$(git rev-parse --short HEAD)"

echo ">> Composer"
# display_errors=0: menyembunyikan peringatan "Deprecation Notice" dari Composer bawaan Ubuntu (tidak mempengaruhi hasil).
php -d display_errors=0 "$(command -v composer)" install --no-dev --optimize-autoloader --no-interaction --no-progress

echo ">> Migrasi database"
artisan migrate --force

echo ">> Cache"
artisan view:clear >/dev/null
artisan optimize >/dev/null
sudo chgrp -R "$WEB_USER" storage bootstrap/cache
sudo chmod -R g+rwX storage bootstrap/cache
sudo systemctl reload "$FPM_SERVICE"

echo ">> Membuka aplikasi"
artisan up
DOWN=0

URL="$(grep -E '^APP_URL=' .env | head -1 | cut -d= -f2- | tr -d "\"' ")"
CODE="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 "${URL%/}/up" 2>/dev/null || true)"
echo ">> Cek ${URL%/}/up: HTTP ${CODE:-gagal}"

echo "=== Update selesai: $OLD -> $NEW ==="
git --no-pager diff --stat "$OLD" "$NEW" 2>/dev/null | tail -1 || true
