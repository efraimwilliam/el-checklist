# Deploy Sistem Checklist Kerjaan ke Ubuntu

> Panduan ini cocok untuk **Ubuntu 24.04 LTS** maupun 26.04 LTS. Di 24.04, Nginx 1.24 memakai `listen 443 ssl http2;` (sudah dipakai di bawah),
> dan PHP bawaan-nya 8.3 (cukup untuk `composer.json`); PHP 8.4 dari `packages.sury.org` tetap bisa dipasang (`$(lsb_release -sc)` = `noble`).

Panduan ini memasang **Sistem Checklist Kerjaan** (Laravel 13, Blade, MySQL) di **Ubuntu Server 26.04 LTS**, mengikuti pola yang sama
dengan `Sistem Absensi/docs/deploy-ubuntu.md` dan `Dashboard/DEPLOY-UBUNTU.md` (Nginx, PHP 8.4, MySQL 8.4, sertifikat wildcard
Cloudflare, Deploy Key GitHub, cron, backup). Checklist dipasang di **server yang sama** dengan Absensi, Inventory, dan Portal.

> **Soal login SSO.** Checklist mendukung **Portal SSO** (OAuth2 lewat Portal, seperti Absensi dan Inventory) dan tetap bisa login dengan
> username + PIN lewat **API Sistem Absensi**. Keduanya diatur di `.env` dan di Portal (mode **Wajib SSO / Keduanya / Login sendiri**).
> Koneksi ke Absensi (tahap 6) **selalu diperlukan**: data pegawai, jadwal kerja, dan PIN tetap bersumber dari sana. SSO hanya mengganti cara masuk (tahap 13).

| Aplikasi | Domain | Folder | Database | Repo GitHub / branch |
|---|---|---|---|---|
| Checklist | `checklist.elhotelresortsanur.my.id` | `/var/www/checklist` | `checklist` (baru) | `el-checklist` / `main` |
| Absensi (sudah ada) | `absen.elhotelresortsanur.my.id` | `/var/www/absensi` | `absensi` | `sistem-absensi` / `sso` |

| Komponen | Fungsi | Lokasi |
|---|---|---|
| Nginx | Web server | `/etc/nginx` |
| PHP 8.4 + PHP-FPM | Menjalankan Laravel | `/etc/php/8.4` |
| MySQL 8.4 LTS | Database | `/var/lib/mysql` |
| certbot + Cloudflare | Sertifikat HTTPS wildcard | `/etc/letsencrypt` |
| cron | `schedule:run` tiap menit + backup | `crontab`, `/etc/cron.d` |
| Foto bukti checklist | Disk privat (bukan folder publik) | `storage/app/private/checklist-photos` |
| Backup harian | Database, foto, laporan, `.env` | `/var/backups/checklist` |

Urutan: **Persiapan → Fondasi server → DNS & sertifikat → Deploy Key → Database → Kode & `.env` → Nginx → Scheduler → Data awal → Keamanan → Backup → Uji.**

> Semua perintah diketik di terminal VM Ubuntu (SSH atau konsol Proxmox). Ganti semua yang bertanda `<...>`.
> Baris `sudo` meminta password akun Ubuntu Anda.

---

## 0. Yang perlu disiapkan dulu

- [ ] Server Ubuntu 26.04 sudah jalan (VM Proxmox, IP tetap) dan **Sistem Absensi sudah terpasang dan bisa dibuka**. Checklist tidak bisa login tanpa Absensi.
- [ ] Absensi sudah memuat endpoint untuk Checklist (`/api/v1/tasks/...`) dan `TASKS_API_TOKEN` terisi di `.env` Absensi (tahap 6).
- [ ] Akses **Cloudflare** (zona `elhotelresortsanur.my.id`) dan **GitHub** (repo `el-checklist`).
- [ ] Semua perubahan lokal sudah di-commit dan di-push ke `main`. **`public/build` ikut di Git** (server tanpa Node.js): jalankan `npm run build` di Mac sebelum commit bila CSS/JS/view berubah.
- [ ] Mail: bila ingin kirim email nanti, siapkan akun SMTP. Saat ini ringkasan mingguan hanya disimpan sebagai file (belum dikirim).
- [ ] Hardisk/NAS/PC lain sebagai **tujuan salinan backup** (tahap 11).

---

## 1. Fondasi server

Bila server sudah dipakai Absensi/Portal, **lewati ke 1.4**: Nginx, PHP 8.4, MySQL 8.4, Composer, dan Git sudah ada.
Server baru: ikuti `Sistem Absensi/docs/deploy-ubuntu.md` bagian 1–3 (Proxmox, VM, jaringan, paket dasar). Ringkasannya:

### 1.1 Sistem dasar
```bash
sudo apt update && sudo apt full-upgrade -y
sudo timedatectl set-timezone Asia/Makassar
sudo apt install -y nginx git unzip zip curl rsync ca-certificates qemu-guest-agent unattended-upgrades
sudo systemctl enable --now qemu-guest-agent
```
Pastikan jam benar: `timedatectl` harus `System clock synchronized: yes` (jadwal checklist memakai WITA).

### 1.2 PHP 8.4 + ekstensi
Ubuntu 26.04 membawa PHP 8.5, jadi PHP 8.4 dipasang dari `packages.sury.org` (sama seperti panduan Absensi):
```bash
sudo apt install -y lsb-release ca-certificates curl
sudo curl -sSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
sudo dpkg -i /tmp/debsuryorg-archive-keyring.deb
echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" | sudo tee /etc/apt/sources.list.d/php.list
sudo apt update
sudo apt install -y php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl \
  php8.4-zip php8.4-gd php8.4-bcmath php8.4-intl php8.4-opcache
php -v        # harus PHP 8.4.x (composer.json meminta ^8.3)
php -m | grep -i -E "gd|zip|mbstring|xml|intl|pdo_mysql|opcache"
```
Kegunaan ekstensi di Checklist: `gd` (olah ulang foto bukti), `zip` + `xml` (ekspor Excel/PhpSpreadsheet), `mbstring`/`intl` (teks), `pdo_mysql` (database).

Batas unggah dan memori (foto bukti sampai 8 MB, ekspor Excel):
```bash
sudo tee /etc/php/8.4/fpm/conf.d/99-checklist.ini > /dev/null <<'EOF'
upload_max_filesize = 8M
post_max_size = 12M
memory_limit = 512M
max_execution_time = 120
date.timezone = Asia/Makassar
opcache.enable = 1
opcache.memory_consumption = 128
opcache.validate_timestamps = 1
EOF
sudo cp /etc/php/8.4/fpm/conf.d/99-checklist.ini /etc/php/8.4/cli/conf.d/99-checklist.ini
sudo systemctl restart php8.4-fpm
```
Bila server sudah punya `99-absensi.ini` dengan nilai yang sama atau lebih besar, file ini tidak perlu (PHP-FPM satu untuk semua situs).

### 1.3 MySQL 8.4 LTS dan Composer
Ikuti `Sistem Absensi/docs/deploy-ubuntu.md` bagian 3.3 dan 3.4 bila belum terpasang. Cek:
```bash
mysql --version        # 8.4.x
composer --version
```

### 1.4 Tidak perlu Node.js
Aset front-end (`public/build`) sudah dibuat di Mac dan ikut Git. Jangan pasang Node di server ini.

---

## 2. DNS dan sertifikat HTTPS

HTTPS **wajib**: kamera di halaman checklist memakai `getUserMedia` (butuh HTTPS atau localhost) dan cookie sesi diset `Secure`.

1. **Cloudflare → DNS → Add record**: Type `A`, Name `checklist`, IPv4 = IP server (mis. `172.16.30.46`), **DNS only** (awan abu-abu).
   Bila sudah ada record wildcard `*` ke IP server, langkah ini tidak perlu.
2. **Sertifikat**. Bila Portal sudah membuat sertifikat wildcard `*.elhotelresortsanur.my.id`, `checklist` sudah tercakup, **tidak perlu sertifikat baru**.
   Cek: `sudo certbot certificates`. Bila belum ada wildcard:
```bash
sudo apt install -y certbot python3-certbot-dns-cloudflare
sudo mkdir -p /root/.secrets
sudo tee /root/.secrets/cloudflare.ini > /dev/null <<'EOF'
dns_cloudflare_api_token = <token-cloudflare>
EOF
sudo chmod 600 /root/.secrets/cloudflare.ini
sudo certbot certonly --dns-cloudflare --dns-cloudflare-credentials /root/.secrets/cloudflare.ini \
  -d elhotelresortsanur.my.id -d '*.elhotelresortsanur.my.id' --agree-tos -m <email-it-kantor> --no-eff-email
echo 'deploy-hook = systemctl reload nginx' | sudo tee -a /etc/letsencrypt/cli.ini
sudo certbot renew --dry-run
```
Bila hanya punya sertifikat per-domain, buat khusus: `... -d checklist.elhotelresortsanur.my.id` dan ubah jalur sertifikat di Nginx (tahap 7).

---

## 3. Izin mengambil kode dari GitHub (Deploy Key)

Repo privat tidak bisa di-clone dengan password. Pakai **Deploy Key** (hanya baca, satu kunci per repo):
```bash
mkdir -p ~/.ssh && chmod 700 ~/.ssh
ssh-keygen -t ed25519 -C "elhotel-checklist" -f ~/.ssh/deploy_checklist -N ""
cat >> ~/.ssh/config <<'EOF'
Host gh-checklist
  HostName github.com
  User git
  IdentityFile ~/.ssh/deploy_checklist
  IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config
ssh-keyscan github.com >> ~/.ssh/known_hosts
cat ~/.ssh/deploy_checklist.pub        # salin satu baris "ssh-ed25519 AAAA... elhotel-checklist"
```
Di GitHub: https://github.com/efraimwilliam/el-checklist → **Settings → Deploy keys → Add deploy key**. Title `server`, tempel kunci,
**jangan** centang *Allow write access*, klik **Add key**. Cek di server:
```bash
ssh -T git@gh-checklist
```
Berhasil bila: `Hi efraimwilliam/el-checklist! You've successfully authenticated, but GitHub does not provide shell access.`
`Permission denied (publickey)` berarti kunci belum ditempel di repo yang tepat. Jangan pernah mengirim file kunci **tanpa** `.pub`.

---

## 4. Database

```bash
sudo mysql -u root -p <<'EOF'
CREATE DATABASE checklist CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'checklist'@'localhost' IDENTIFIED BY '<password-database>';
GRANT ALL PRIVILEGES ON checklist.* TO 'checklist'@'localhost';
FLUSH PRIVILEGES;
EOF
```
Bila root MySQL dikosongkan saat pemasangan, cukup `sudo mysql` tanpa `-u root -p`. Password database aplikasi berbeda dari password root.

---

## 5. Kode, `.env`, dan izin folder

```bash
sudo mkdir -p /var/www && sudo chown $USER:www-data /var/www
cd /var/www
git clone git@gh-checklist:efraimwilliam/el-checklist.git checklist
cd checklist
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
nano .env
```

Isi `.env` (selain yang sudah ada di `.env.example`, ubah/isi bagian ini):
```ini
APP_NAME="Checklist Kerjaan"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://checklist.elhotelresortsanur.my.id
APP_TIMEZONE=Asia/Makassar
APP_LOCALE=id

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=checklist
DB_USERNAME=checklist
DB_PASSWORD=<password-database>

# Satu file laravel.log: halaman Kesehatan sistem membaca error 24 jam terakhir dari file ini
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning

# Sesi (batas diam 6 jam, batas mutlak 12 jam, sesuai tutorial)
SESSION_DRIVER=database
SESSION_COOKIE=checklist_session
SESSION_LIFETIME=360
SESSION_ABSOLUTE_LIFETIME=720
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

# Sistem Absensi (login username + PIN, data pegawai, jadwal kerja)
ATTENDANCE_API_URL=https://absen.elhotelresortsanur.my.id
ATTENDANCE_API_TOKEN=<sama persis dengan TASKS_API_TOKEN di .env Absensi>
ATTENDANCE_API_VERIFY_TLS=true

# Opsional
AUDIT_RETENTION_DAYS=180
CHECKLIST_PENDING_NOTE_REQUIRED=true
```
Catatan:
- `SESSION_COOKIE=checklist_session` supaya tidak bentrok dengan cookie Absensi/Portal di domain induk yang sama.
- `ATTENDANCE_API_VERIFY_TLS=false` **ditolak saat boot di produksi**. Jangan pernah dipakai.
- Aplikasi memanggil Absensi dari server ini lewat nama domain. Pastikan server bisa me-resolve `absen.elhotelresortsanur.my.id` (cek di tahap 6).
- Token jangan di-commit dan jangan dikirim lewat chat. Saat `.env` diubah, jalankan `php artisan optimize`.

Lanjutkan:
```bash
php artisan migrate --force
php artisan storage:link        # aman dijalankan; foto bukti tetap dilayani lewat route berizin, bukan folder publik
php artisan optimize
```

Izin folder (web server harus bisa menulis `storage` dan `bootstrap/cache`):
```bash
sudo chown -R $USER:www-data /var/www/checklist
sudo find /var/www/checklist/storage /var/www/checklist/bootstrap/cache -type d -exec chmod 2775 {} \;
sudo find /var/www/checklist/storage /var/www/checklist/bootstrap/cache -type f -exec chmod 664 {} \;
chmod 640 /var/www/checklist/.env
```
> Jangan jalankan seeder demo di server. Foto bukti disimpan di `storage/app/private/checklist-photos` (bukan folder publik).

---

## 6. Hubungkan ke Sistem Absensi (pengganti "SSO" saat ini)

Checklist tidak punya password/PIN sendiri. Setiap login diteruskan ke Absensi:
`POST /api/v1/tasks/auth/verify-password` dicoba dulu (untuk HOD/HR/Administrator), lalu `POST /api/v1/tasks/auth/verify-pin`.
Data pegawai/departemen dan jadwal kerja juga diambil dari Absensi.

**a. Buat token di Absensi** (sekali):
```bash
openssl rand -hex 32            # hasilnya dipakai sebagai token
nano /var/www/absensi/.env      # tambahkan/ubah: TASKS_API_TOKEN=<token tadi>
cd /var/www/absensi && php artisan optimize
```
**b. Salin token yang sama** ke `ATTENDANCE_API_TOKEN` di `.env` Checklist, lalu `php artisan optimize` di Checklist.

**c. Uji koneksi** dari server Checklist:
```bash
cd /var/www/checklist
getent hosts absen.elhotelresortsanur.my.id                       # harus muncul IP server
curl -sS -o /dev/null -w "tanpa token: %{http_code}\n" https://absen.elhotelresortsanur.my.id/api/v1/tasks/departments
curl -sS -H "Authorization: Bearer $(grep ^ATTENDANCE_API_TOKEN= .env | cut -d= -f2-)" \
  https://absen.elhotelresortsanur.my.id/api/v1/tasks/departments | head -c 300; echo
```
- Tanpa token harus **401/403**, bukan 404 (404 = Absensi di server belum membawa endpoint `tasks`; update Absensi dulu).
- Dengan token harus muncul JSON daftar departemen. Cara Absensi memeriksa token (header) bisa berbeda; bila 401 padahal token benar, cek `config/attendance.php` dan `AttendanceApiService` di Checklist.
- Sertifikat error: pastikan `curl` tanpa `-k` berhasil. Jangan matikan verifikasi TLS.

**d. Interaksi dengan Portal SSO.** Selama Absensi diset **Wajib SSO**, password lokal Absensi tidak lagi diperbarui, padahal Checklist (mode login PIN) memverifikasi
password/PIN ke Absensi. Karena itu biarkan Absensi di mode **Keduanya** sampai semua pengguna Checklist masuk lewat Portal (lihat tahap 13).

> **Peringatan versi Absensi.** Branch `sso` di GitHub (`sistem-absensi`) saat ini **belum berisi** pembaruan `main` yang menambah endpoint API untuk Checklist
> (`TasksIntegrationController`, `verify-password`, `account`, `schedules`). Bila server memakai branch `sso` apa adanya, curl di langkah c memberi 404 dan Checklist tidak bisa
> login, sinkron, atau membaca jadwal. Gabungkan `main` ke `sso` (atau sebaliknya) di repo Absensi sebelum deploy, lalu uji ulang langkah c.

---

## 7. Nginx

Blok `server` untuk Checklist. Batas kecepatan login per IP (zona dibuat di langkah berikut, Nginx menolak start bila zona belum ada):
```bash
sudo tee /etc/nginx/conf.d/checklist-limit.conf > /dev/null <<'EOF'
map "$request_method $request_uri" $checklist_login_key { default ""; "~^POST /login" $binary_remote_addr; }
limit_req_zone $checklist_login_key zone=checklist_login:10m rate=10r/m;
limit_req_zone $binary_remote_addr zone=checklist_all:10m rate=20r/s;
EOF
```
(Aplikasi sendiri sudah mengunci 5 gagal / 15 menit per username+IP dan throttle 20/menit; ini lapisan tambahan di Nginx.
Bila `limit_req_status 429;` sudah ada dari `absensi-limit.conf`, jangan ditulis dua kali.)

```bash
sudo tee /etc/nginx/sites-available/checklist > /dev/null <<'EOF'
server {
    listen 80;
    server_name checklist.elhotelresortsanur.my.id;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;   # Nginx 1.24 (Ubuntu 24.04) belum mengenal "http2 on;"
    server_name checklist.elhotelresortsanur.my.id;
    root /var/www/checklist/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/elhotelresortsanur.my.id/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/elhotelresortsanur.my.id/privkey.pem;

    # Foto dikecilkan di HP; server menerima sampai 8 MB per foto
    client_max_body_size 12M;
    charset utf-8;
    add_header X-Content-Type-Options nosniff;
    add_header Referrer-Policy strict-origin-when-cross-origin;
    # Kamera butuh izin dari halaman sendiri saja
    add_header Permissions-Policy "camera=(self), microphone=(), geolocation=()";

    location /build/ { expires 1y; add_header Cache-Control "public, immutable"; }
    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        limit_req zone=checklist_login burst=5 nodelay;
        limit_req zone=checklist_all burst=60 nodelay;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 120;
    }

    location ~ /\.(?!well-known) { deny all; }
}
EOF
sudo ln -s /etc/nginx/sites-available/checklist /etc/nginx/sites-enabled/checklist
sudo nginx -t && sudo systemctl reload nginx
```
Bila memakai sertifikat per-domain (bukan wildcard), ganti dua jalur `ssl_certificate*` ke `/etc/letsencrypt/live/checklist.elhotelresortsanur.my.id/`.

Tes cepat: buka `https://checklist.elhotelresortsanur.my.id/up` (harus 200) lalu halaman login muncul.

---

## 8. Jadwal otomatis (cron) — wajib

Scheduler harus berjalan **tiap menit**. Isinya: `attendance:sync` (tiap jam), `checklist:auto-assign` (tiap 5 menit),
`checklist:prune-empty` (03:00), `checklist:prune-photos` (03:30), `audit:prune` (03:40), `laporan:mingguan` (Senin 07:00 WITA), dan denyut untuk halaman Kesehatan sistem.
```bash
sudo crontab -u www-data -e
```
Tambahkan:
```cron
* * * * * cd /var/www/checklist && php artisan schedule:run >> /dev/null 2>&1
```
Cek setelah 2 menit: `/admin/pengaturan` → **Kesehatan sistem** → bagian jadwal harus hijau. Tanpa cron: pegawai baru tidak ikut tersinkron,
checklist otomatis tidak dibuat, dan foto lama tidak dihapus.

---

## 9. Data awal dan administrator pertama

Urutan penting: **sinkron dulu**, baru beri akses.
```bash
cd /var/www/checklist

# 1. Tarik departemen & pegawai dari Absensi
sudo -u www-data php artisan attendance:sync

# 2. (Opsional) impor jabatan & tugas dari workbook checklist lama; sheet Akun (PIN) tidak dibaca; boleh diulang
php artisan checklist:import-workbook /home/<akun>/checklist-lama.xlsx

# 3. Administrator pertama (username = username di Absensi)
php artisan checklist:grant <username-absensi-anda> admin
```
Lalu login di `https://checklist.elhotelresortsanur.my.id` dengan username + PIN (atau password dashboard Absensi bagi HOD/HR/Administrator),
dan atur peran lain di `/admin/akses`:
1. HOD: centang departemennya. HR/GM/Administrator: departemen dikosongkan.
2. Atur **jabatan checklist** tiap pegawai (`/tim/jabatan`) dan **tugas** (`/admin/tugas` atau Daftar tugas).
3. Atur jam **pembuatan otomatis** per departemen di `/admin/pengaturan`.
4. Atur aturan foto (opsional/wajib untuk Pending/wajib semua) dan masa simpan foto (bawaan 90 hari).

Pesan saat login yang bukan salah server:
- `pin_not_set`: pegawai belum punya PIN. Minta HR/HOD membuka izin buat PIN di Absensi, lalu pegawai membuat PIN di aplikasi absensi.
- `password_must_change`: ganti password sementara di Absensi dulu.
- Akun aktif di Absensi tetapi tidak bisa masuk: belum punya baris di `/admin/akses` (jalankan `attendance:sync` lalu beri peran).

---

## 10. Keamanan

### 10.1 Firewall
Bila belum diatur untuk Absensi/Portal (satu firewall untuk seluruh server):
```bash
sudo ufw allow from 172.16.0.0/16 to any port 22 proto tcp   # SSH hanya dari jaringan kantor (sesuaikan)
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
sudo ufw status
```

### 10.2 fail2ban
Aturan `absensi-scan` dari panduan Absensi membaca `/var/log/nginx/access.log` (semua situs), jadi Checklist ikut terlindungi dari penembakan
jalur acak tanpa pengaturan tambahan. Catatan login gagal Checklist ada di `storage/logs/auth-*.log` (channel `auth`, rotasi harian) dan
formatnya berbeda dari Absensi; filter fail2ban khusus Checklist **belum dibuat**. Pagar yang berlaku untuk login saat ini: pengunci aplikasi
(5 gagal / 15 menit) + `limit_req` Nginx di atas.

### 10.3 Perputaran log
`laravel.log` satu file akan terus membesar. Putar mingguan (`copytruncate` agar file tetap bisa ditulis):
```bash
sudo tee /etc/logrotate.d/checklist > /dev/null <<'EOF'
/var/www/checklist/storage/logs/laravel.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    copytruncate
}
EOF
```
Log `auth-*.log` harian: hapus yang lama bila perlu (`find /var/www/checklist/storage/logs -name 'auth-*.log' -mtime +90 -delete`).
Tabel `audit_logs` sudah dipangkas otomatis oleh `audit:prune` (bawaan 180 hari).

### 10.4 Hal yang perlu dijaga
- `/var/www/checklist` dan `/var/backups/checklist` berisi foto kerja, data pegawai, dan catatan. Hanya akun admin server yang boleh SSH.
- Domain di belakang proxy (mis. Cloudflare *proxied*): atur `trustProxies` Laravel dan `real_ip_header` Nginx dulu, kalau tidak batas kecepatan melihat IP proxy.
- Perubahan `.env` selalu diikuti `php artisan optimize`.

---

## 11. Backup harian

Yang perlu dicadangkan: database `checklist`, foto bukti (`storage/app/private/checklist-photos`), ringkasan mingguan
(`storage/app/private/laporan`), dan `.env` (berisi `APP_KEY` dan token Absensi).

### 11.1 Kredensial database untuk backup
```bash
sudo mkdir -p /var/backups/checklist && sudo chmod 750 /var/backups/checklist
sudo tee /var/backups/checklist/mysql-backup.cnf > /dev/null <<'EOF'
[client]
user=checklist
password=<password-database>
EOF
sudo chmod 600 /var/backups/checklist/mysql-backup.cnf
```

### 11.2 Skrip backup
```bash
sudo tee /usr/local/bin/checklist-backup.sh > /dev/null <<'EOF'
#!/bin/bash
# Backup harian Sistem Checklist. Catatan di backup.log.
set -u
APP=/var/www/checklist
DIR=/var/backups/checklist
KEEP_DAYS=30
LOG=$DIR/backup.log
TODAY=$(date +%F)
PRIV=$APP/storage/app/private
mkdir -p "$DIR/database" "$DIR/aplikasi"

OUT=$DIR/database/checklist-$TODAY.sql
if ! mysqldump --defaults-extra-file="$DIR/mysql-backup.cnf" --single-transaction --no-tablespaces --set-gtid-purged=OFF checklist > "$OUT.tmp" 2> "$DIR/mysqldump-error.txt"; then
    rm -f "$OUT.tmp"
    echo "$(date '+%F %T') GAGAL backup database: $(cat "$DIR/mysqldump-error.txt")" >> "$LOG"
    exit 1
fi
mv -f "$OUT.tmp" "$OUT"; rm -f "$DIR/mysqldump-error.txt"

for pair in checklist-photos:foto-bukti laporan:laporan; do
    src=${pair%%:*}; dst=${pair##*:}
    [ -d "$PRIV/$src" ] && rsync -a "$PRIV/$src/" "$DIR/$dst/"
done
cp -f "$APP/.env" "$DIR/aplikasi/.env"
find "$DIR/database" -name '*.sql' -mtime +$KEEP_DAYS -delete
echo "$(date '+%F %T') backup selesai: $OUT" >> "$LOG"

# Salinan ke luar server: satu folder per baris di tujuan-backup.txt (mis. /mnt/backup-usb/checklist)
if [ -f "$DIR/tujuan-backup.txt" ]; then
    grep -v '^\s*#' "$DIR/tujuan-backup.txt" | while read -r dest; do
        [ -z "$dest" ] && continue
        if mkdir -p "$dest" && rsync -a --exclude 'mysql-backup.cnf' "$DIR/" "$dest/"; then
            echo "$(date '+%F %T') salinan ke $dest selesai" >> "$LOG"
        else
            echo "$(date '+%F %T') GAGAL menyalin ke $dest" >> "$LOG"
        fi
    done
fi
EOF
sudo chmod 750 /usr/local/bin/checklist-backup.sh
sudo /usr/local/bin/checklist-backup.sh && tail -2 /var/backups/checklist/backup.log
```
Harus tampil `backup selesai: ...`. Foto yang sudah dihapus karena melewati masa simpan tetap ada di salinan backup (rsync tanpa `--delete`);
hapus manual salinan lama bila ruang penuh.

### 11.3 Jadwalkan, dan salinan ke luar server
```bash
echo '30 12 * * * root /usr/local/bin/checklist-backup.sh' | sudo tee /etc/cron.d/checklist-backup
```
(12:30, setelah backup Absensi pukul 12:00 agar tidak bersamaan.) Untuk salinan luar server (wajib bila hanya satu hardisk), tulis folder tujuan di
`/var/backups/checklist/tujuan-backup.txt`, mis. `/mnt/backup-usb/checklist`. **Backup VM utuh** dari Proxmox (mingguan, mode Snapshot, ke disk lain)
tetap dianjurkan; satu VM yang sama mencakup Checklist.

### 11.4 Memulihkan
```bash
cd /var/www/checklist
php artisan down --render="errors::503"
mysql --defaults-extra-file=/var/backups/checklist/mysql-backup.cnf checklist < /var/backups/checklist/database/checklist-YYYY-MM-DD.sql
rsync -a /var/backups/checklist/foto-bukti/ storage/app/private/checklist-photos/
rsync -a /var/backups/checklist/laporan/    storage/app/private/laporan/
php artisan migrate --force
php artisan optimize
sudo chown -R $USER:www-data storage
php artisan up
```
Pada server baru, pakai `APP_KEY` yang **sama** dengan `.env` lama (sesi lama tidak terbaca bila berbeda, tetapi data tetap utuh).
Coba pulihkan ke database kosong sebulan sekali untuk memastikan backup bisa dipakai.

---

## 12. Update aplikasi di kemudian hari

Karena server tanpa Node.js, **build aset dilakukan di Mac**, lalu di-push. Alurnya:

**Di Mac:**
```bash
npm run build                       # bila CSS/JS/view berubah
php artisan test
git add -A && git commit -m "..." && git push origin main
```
**Di server** (pengganti `update.bat`):
```bash
sudo tee /usr/local/bin/checklist-update.sh > /dev/null <<'EOF'
#!/bin/bash
# Update Sistem Checklist ke versi terbaru dari GitHub. Selama update pengunjung melihat halaman pemeliharaan.
set -e
cd /var/www/checklist
php artisan down --render="errors::503" --retry=15 --refresh=15
trap 'php artisan up; echo "=== Update GAGAL. Baca pesan error di atas. ==="' ERR
git pull
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan optimize
sudo systemctl reload php8.4-fpm
php artisan up
echo "=== Update selesai ==="
EOF
sudo chmod 755 /usr/local/bin/checklist-update.sh
```
Cara pakai: `checklist-update.sh`. Setelah update, cek **Kesehatan sistem** (migrasi, jadwal, error 24 jam).

---

## 13. Portal SSO

Portal (`elhotelresortsanur.my.id`) mengelola SSO untuk Absensi, Inventory, dan Digital Signage. Checklist ikut dengan pola yang sama
(`/sso/login`, `/sso/callback`, `/sso/logout`). Alur: pengguna menekan **Masuk lewat Portal** → Portal → kembali ke `/sso/callback` → server Checklist
menukar kode jadi token dan memanggil `GET /api/user` di Portal → akun lokal dicocokkan lewat **username** (= username Absensi di tabel pegawai),
lalu dikunci di `users.sso_user_id`. **Peran dan departemen tetap diatur di Checklist** (`/admin/akses`), bukan di Portal. Pengguna Portal yang belum
punya akses di Checklist ditolak dengan pesan yang jelas.

**Syarat:** Portal sudah terpasang (`Dashboard/DEPLOY-UBUNTU.md`), akun pengguna ada di Portal dengan username sama seperti di Absensi,
dan jam server benar (token bertenggat).

### 13.1 Daftarkan Checklist di Portal
Login Portal sebagai administrator → **Kelola → Sistem → Tambah**:
- Nama `Checklist Kerjaan`, key `checklist`, alamat `https://checklist.elhotelresortsanur.my.id`.
- **Mode login: Keduanya** dulu. Centang **login PIN** (`pin_login`) bila pegawai tanpa password dashboard harus masuk ke Portal dengan PIN absen.
- Simpan, lalu salin **Client ID** dan **Client Secret** yang tampil (secret hanya tampil sekali; bisa dibuat ulang lewat "rahasia baru").
- Beri hak akses tiap pengguna: **Kelola → Pengguna → Assign sistem → Checklist Kerjaan**.

Redirect URI yang terdaftar harus persis `https://checklist.elhotelresortsanur.my.id/sso/callback`.

### 13.2 `.env` Checklist
```ini
SSO_PORTAL_URL=https://elhotelresortsanur.my.id
SSO_CLIENT_ID=<dari Portal>
SSO_CLIENT_SECRET=<dari Portal>
SSO_CHECK_MINUTES=5
# akun sistem Portal yang boleh masuk walau bukan pegawai absensi (mis. admin Anda), dipisah koma
SSO_SYSTEM_ACCOUNTS=admin
# darurat, tanpa menghapus isian di atas: SSO_ENABLED=false
```
Lalu:
```bash
cd /var/www/checklist
php artisan migrate --force        # menambah users.sso_user_id dan membolehkan employees.external_id kosong (akun sistem)
php artisan optimize
curl -sS "https://elhotelresortsanur.my.id/api/sso-mode?client_id=<CLIENT_ID>"     # harus {"login_mode":"both"}
```
SSO aktif bila `SSO_PORTAL_URL`, `SSO_CLIENT_ID`, dan `SSO_CLIENT_SECRET` terisi. Kosong semua = perilaku lama (username + PIN) tanpa perubahan.

Akun sistem (mis. `admin` di Absensi, yang tidak ikut sinkronisasi pegawai) hanya masuk lewat **SSO**, bukan username + PIN.
Username-nya harus ada di `SSO_SYSTEM_ACCOUNTS`, dan akun itu harus punya hak **Checklist Kerjaan** di Portal. Login pertama membuat
akun Administrator di Checklist; ubah perannya di `/admin/akses` bila perlu. Akun sistem tidak punya jadwal Absensi, jadi tidak ikut
hitungan "belum punya checklist" di laporan.

### 13.3 Cara kerja di aplikasi
| Mode di Portal | Halaman login | Username + PIN |
|---|---|---|
| **Keduanya** (`both`) | Tombol "Masuk lewat Portal" + form PIN | Diterima (diverifikasi ke Absensi) |
| **Wajib SSO** (`required`) | `/login` langsung diarahkan ke Portal | Ditolak; sesi tanpa Portal dikeluarkan |
| **Login sendiri** (`local`) | Form PIN saja | Diterima; SSO tidak dipakai |

- Mode dibaca dari Portal (`/api/sso-mode`, di-cache 1 menit). Portal tidak terjangkau = dipakai nilai terakhir, atau `required` bila belum pernah terbaca.
- Sesi Portal dicek tiap `SSO_CHECK_MINUTES` menit (keluar dari Portal, akun nonaktif, atau akses dicabut = ikut keluar). Aktivitas dilaporkan ke Portal tiap menit,
  jadi batas "tidak aktif" Portal dihitung dari semua sistem. Portal mati tidak mengeluarkan siapa pun.
- **Keluar** dari sesi Portal = keluar dari Portal dan semua sistem. Tombol **Dashboard Utama** di header membuka beranda Portal.
- Penyimpanan otomatis checklist yang sesinya berakhir menerima 401 JSON (sama seperti sesi habis biasa).
- Log: `login.sso_success` / `login.no_access` di Log aplikasi dan `storage/logs/auth-*.log` (tanpa token).

### 13.4 Urutan pengaktifan
1. Deploy Checklist dengan SSO kosong; pastikan login PIN jalan (tahap 14).
2. Daftarkan di Portal (13.1), isi `.env` (13.2), mode **Keduanya**.
3. Uji: login lewat Portal sebagai administrator, sebagai HOD, dan sebagai staff; keluar; dan Dashboard Utama.
4. Pastikan setiap pengguna aktif Checklist punya akun Portal dengan username sama dan hak **Checklist Kerjaan**. Pengguna tanpa itu tidak akan bisa masuk bila nanti **Wajib SSO**.
5. Ubah ke **Wajib SSO** di Portal (efek sampai 1 menit). Darurat: ubah ke **Login sendiri** di Portal, atau `SSO_ENABLED=false` + `php artisan optimize` di Checklist.
6. Absensi tetap di mode **Keduanya** (lihat 6d).

### 13.6 Admin lokal (tanpa Portal)
Untuk akun sistem (mis. `admin`) yang harus bisa masuk saat SSO dimatikan:
```bash
cd /var/www/checklist && sudo -u www-data php artisan checklist:admin-lokal admin
```
Password diminta di terminal (minimal 8 karakter, diulang untuk konfirmasi). Jalankan lagi untuk mengganti password. Setelah itu, di Portal
ubah mode Checklist ke **Keduanya** atau **Login sendiri**; di halaman login masuk dengan `admin` dan password tadi.
Akun ini tidak ikut sinkronisasi pegawai, dan login-nya tidak pernah ke Absensi.

### 13.5 Catatan
- Pencocokan memakai **username**. Bila username pegawai diganti di Absensi sebelum login Portal pertama, sinkronkan (`attendance:sync`) dan samakan username di Portal.
  Setelah login pertama, akun dikenali lewat id Portal sehingga ganti username tidak memutus.
- Hapus/pindah akun Portal ke orang lain: kosongkan `users.sso_user_id` akun Checklist terkait (`php artisan tinker`), baru login ulang.
- Ganti username/password dari **Profil** di Checklist tetap diteruskan ke Absensi (bukan ke Portal).

---

## 14. Uji sebelum dipakai

- [ ] `https://checklist.elhotelresortsanur.my.id/up` terbuka, sertifikat valid (gembok hijau).
- [ ] Login administrator berhasil; salah PIN menampilkan pesan umum; lima kali salah mengunci akun sementara.
- [ ] `/admin/pengaturan` → **Kesehatan sistem** semuanya hijau: jadwal (denyut `schedule:run`), pembuatan otomatis, sinkron Absensi, penyimpanan, migrasi, HTTPS, koneksi Absensi, TLS, batas unggah (>= 8M / 10M), ekstensi PHP.
- [ ] `/admin/akses` → **Sinkron dari absensi**: pegawai dan departemen muncul.
- [ ] Buat checklist untuk satu staff, isi satu tugas Done dan satu Pending (alasan wajib), skor sementara berubah, simpan otomatis jalan.
- [ ] **Foto**: tombol Kamera meminta izin kamera (HTTPS), ambil foto, tampil kecil; tombol Galeri berfungsi; foto tidak bisa dibuka lewat URL langsung tanpa login.
- [ ] Tandai sudah direview (HOD), lalu review GM/HR; checklist terkunci untuk HOD/staff.
- [ ] **Riwayat**: filter tanggal (kalender emas), **Unduh Excel** berisi data.
- [ ] **Laporan**: tampil, **Cetak** menyembunyikan kontrol, ekspor Excel berhasil.
- [ ] **Dashboard** tampil untuk HOD/HR/GM/Administrator.
- [ ] Profil → ubah username/password dashboard (diteruskan ke Absensi).
- [ ] Cron: tunggu 5–10 menit, pastikan `checklist:auto-assign` jalan untuk departemen yang jamnya sudah lewat; tes manual `sudo -u www-data php artisan checklist:auto-assign`.
- [ ] Backup: jalankan `checklist-backup.sh`, file `.sql` berukuran wajar.
- [ ] Buka dari HP di Wi-Fi staf: login, isi satu kartu tugas, ambil foto.
- [ ] **SSO** (bila diaktifkan, tahap 13): tombol "Masuk lewat Portal" muncul, login lewat Portal masuk ke akun yang benar, Keluar mengeluarkan dari Portal, akun tanpa akses mendapat pesan jelas.

---

## Pemecahan masalah

| Gejala | Penyebab umum | Perbaikan |
|---|---|---|
| Halaman 500 | Izin `storage`/`bootstrap/cache`, `.env` salah, `APP_KEY` kosong | `tail -50 storage/logs/laravel.log`; jalankan ulang izin folder (tahap 5) lalu `php artisan optimize` |
| `502 Bad Gateway` | PHP-FPM mati atau jalur socket salah | `sudo systemctl status php8.4-fpm`; cek `fastcgi_pass` |
| "Akun portal ... belum terdaftar" saat login Portal | Username Portal tidak sama dengan username pegawai, atau belum diberi peran di `/admin/akses` | Samakan username, `attendance:sync`, beri peran |
| Login Portal berputar ke halaman login | Redirect URI di Portal salah, `SSO_CLIENT_*` salah, atau jam server melenceng | Cek 13.1 dan 13.2, `timedatectl` |
| Login selalu gagal / "Sistem Absensi tidak dapat dihubungi" | Token beda, URL salah, DNS/TLS tidak terbaca | Ulangi tahap 6c; lihat `storage/logs/auth-*.log` |
| `pin_not_set` | Pegawai belum membuat PIN di Absensi | HR/HOD buka izin buat PIN, pegawai membuatnya di aplikasi absensi |
| Login benar tapi ditolak | Belum ada peran di `/admin/akses` | `attendance:sync` lalu `checklist:grant <username> admin` |
| Aplikasi menolak boot | `ATTENDANCE_API_VERIFY_TLS=false` di produksi | Set `true`; perbaiki sertifikat, bukan verifikasinya |
| Kesehatan sistem: jadwal merah | Cron `schedule:run` belum jalan | Cek `sudo crontab -u www-data -l` |
| Foto gagal diunggah | `upload_max_filesize`/`post_max_size` kecil atau `client_max_body_size` Nginx | Tahap 1.2 dan 7, lalu restart php-fpm dan reload nginx |
| Tombol Kamera tidak berfungsi | Bukan HTTPS atau izin kamera ditolak | Pakai HTTPS; izinkan kamera di browser (jatuh ke kamera bawaan HP bila gagal) |
| Tampilan berantakan setelah update | `public/build` tidak ikut di-commit | Di Mac: `npm run build`, commit, push, lalu update lagi |
| Pegawai baru tidak muncul | Sinkron belum jalan | `sudo -u www-data php artisan attendance:sync` atau tombol "Sinkron dari absensi" |
| Libur/cuti tetap dihitung "belum punya checklist" | Jadwal Absensi tidak terbaca | Halaman menyebutkannya; cek koneksi ke Absensi dan versi endpoint `schedules` |

---

## Ringkasan perintah harian

| Tugas | Perintah |
|---|---|
| Update aplikasi | `checklist-update.sh` |
| Backup manual | `sudo /usr/local/bin/checklist-backup.sh` |
| Sinkron pegawai | `sudo -u www-data php artisan attendance:sync` |
| Beri peran admin | `php artisan checklist:grant <username> admin` |
| Muat ulang konfigurasi | `php artisan optimize` |
| Lihat error | `tail -f storage/logs/laravel.log` |
| Nonaktifkan sementara | `php artisan down --render="errors::503"` / `php artisan up` |
