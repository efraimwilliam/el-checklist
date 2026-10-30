<?php

namespace App\Health;

use App\Checklists\ChecklistSettings;
use App\Models\ChecklistItemPhoto;
use App\Models\Department;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/**
 * Pemeriksaan kesehatan untuk Administrator: jadwal, penyimpanan, aplikasi, dan server.
 * Status: ok (Baik), info (Info), warn (Perlu dicek), bad (Gagal). Tidak ada pemeriksaan yang memanggil Absensi (tidak membebani API).
 */
final class SystemHealth
{
    public const SCHEDULER_CACHE = 'scheduler.heartbeat';

    public const PHOTO_PRUNE_CACHE = 'photos.last_prune';

    /**
     * @return list<array{title: string, checks: list<array{label: string, status: string, value: string, detail: ?string}>}>
     */
    public function groups(): array
    {
        return [
            ['title' => __('Jadwal otomatis & pembuatan'), 'checks' => $this->schedule()],
            ['title' => __('Penyimpanan'), 'checks' => $this->storage()],
            ['title' => __('Aplikasi'), 'checks' => $this->application()],
            ['title' => __('Server'), 'checks' => $this->server()],
        ];
    }

    /** @return array{ok: int, warn: int, bad: int} */
    public function summary(array $groups): array
    {
        $checks = collect($groups)->pluck('checks')->flatten(1);

        return [
            'ok' => $checks->where('status', 'ok')->count(),
            'warn' => $checks->where('status', 'warn')->count(),
            'bad' => $checks->where('status', 'bad')->count(),
        ];
    }

    private function schedule(): array
    {
        $checks = [];
        $settings = app(ChecklistSettings::class);

        $beat = Cache::get(self::SCHEDULER_CACHE);
        if ($beat === null) {
            $checks[] = $this->check(__('Jadwal otomatis (schedule:run)'), 'warn', __('Belum tercatat'), __('Pastikan cron server menjalankan "php artisan schedule:run" setiap menit.'));
        } else {
            $at = CarbonImmutable::parse($beat);
            $fresh = $at->gte(now()->subMinutes(5));
            $checks[] = $this->check(__('Jadwal otomatis (schedule:run)'), $fresh ? 'ok' : 'warn', $fresh ? __('Berjalan') : __('Berhenti'), __('Terakhir :time', ['time' => $at->format('d M H:i')]));
        }

        $lastAuto = Department::max('auto_assigned_on');
        $scheduled = Department::whereNotNull('auto_assign_at')->count();
        $checks[] = $this->check(__('Pembuatan checklist otomatis'), 'info', $lastAuto ? CarbonImmutable::parse($lastAuto)->format('d M Y') : __('Belum pernah'), __(':n departemen dijadwalkan', ['n' => $scheduled]));

        $sync = Employee::max('synced_at');
        $syncAge = $sync ? CarbonImmutable::parse($sync)->diffInMinutes(now()) : null;
        $checks[] = $this->check(__('Sinkron data Absensi'), $syncAge !== null && $syncAge <= 120 ? 'ok' : 'warn', $sync ? CarbonImmutable::parse($sync)->format('d M H:i') : __('Belum pernah'), __('Dijalankan tiap jam.'));

        $retention = $settings->photoRetentionDays();
        $prune = Cache::get(self::PHOTO_PRUNE_CACHE);
        if ($retention === 0) {
            $checks[] = $this->check(__('Hapus foto lama (03:30)'), 'info', __('Mati'), __('Masa simpan foto 0 = foto tidak pernah dihapus.'));
        } else {
            $ok = $prune && CarbonImmutable::parse($prune['at'])->gte(now()->subDays(2));
            $checks[] = $this->check(__('Hapus foto lama (03:30)'), $ok ? 'ok' : 'warn', $prune ? CarbonImmutable::parse($prune['at'])->format('d M H:i') : __('Belum pernah'), $prune ? __(':n foto dihapus pada proses terakhir', ['n' => $prune['deleted']]) : __('Simpan :days hari.', ['days' => $retention]));
        }

        $reports = Storage::disk('local')->files('laporan');
        $latest = collect($reports)->sortDesc()->first();
        $checks[] = $this->check(__('Ringkasan mingguan'), 'info', $latest ? basename($latest) : __('Belum ada'), __('Dibuat tiap Senin 07:00 WITA. Pengiriman email menunggu alamat GM.'));

        $checks[] = $this->check(__('Backup database'), 'info', __('Di luar aplikasi'), __('Backup dijalankan di server; tidak diatur dari aplikasi ini.'));

        return $checks;
    }

    private function storage(): array
    {
        $checks = [];

        $total = (float) disk_total_space(base_path());
        $free = (float) disk_free_space(base_path());
        $share = $total > 0 ? $free / $total : 0;
        $status = $share < 0.05 ? 'bad' : ($share < 0.15 ? 'warn' : 'ok');
        $checks[] = $this->check(__('Sisa ruang disk'), $status, Number::fileSize($free, 1).' / '.Number::fileSize($total, 1), __(':n% kosong', ['n' => round($share * 100, 1)]));

        $photos = ChecklistItemPhoto::query();
        $checks[] = $this->check(__('Foto bukti'), 'info', Number::format($photos->count()).' '.__('file'), Number::fileSize((int) $photos->sum('size'), 1));

        $checks[] = $this->check(__('Database'), 'info', $this->databaseSize(), $this->databaseVersion());

        $writable = is_writable(storage_path());
        $checks[] = $this->check(__('Folder storage bisa ditulis'), $writable ? 'ok' : 'bad', $writable ? __('Ya') : __('Tidak'), null);

        return $checks;
    }

    private function application(): array
    {
        $checks = [];

        $checks[] = $this->check(__('Versi Laravel'), 'info', app()->version(), null);

        $production = config('app.env') === 'production' && ! config('app.debug');
        $checks[] = $this->check(__('Mode aplikasi'), $production ? 'ok' : 'warn', (string) config('app.env'), $production ? null : __('Untuk produksi: APP_ENV=production dan APP_DEBUG=false.'));

        $https = str_starts_with((string) config('app.url'), 'https://');
        $checks[] = $this->check('HTTPS', $https ? 'ok' : 'warn', parse_url((string) config('app.url'), PHP_URL_HOST) ?: '—', $https ? null : __('Kamera untuk foto butuh HTTPS.'));

        $files = collect(glob(database_path('migrations/*.php')))->map(fn ($file) => basename($file, '.php'));
        $pending = $files->diff(DB::table('migrations')->pluck('migration'))->count();
        $checks[] = $this->check(__('Struktur database (migrasi)'), $pending === 0 ? 'ok' : 'bad', $pending === 0 ? __('Terbaru') : __(':n belum dijalankan', ['n' => $pending]), $pending === 0 ? null : __('Jalankan: php artisan migrate --force'));

        $errors = $this->errorsLastDay();
        $checks[] = $this->check(__('Error aplikasi (24 jam)'), $errors === 0 ? 'ok' : 'warn', (string) $errors, $errors === 0 ? null : __('Lihat storage/logs/laravel.log.'));

        $absensi = filled(config('attendance.url')) && filled(config('attendance.token'));
        $checks[] = $this->check(__('Koneksi Sistem Absensi'), $absensi ? 'ok' : 'bad', $absensi ? __('Diatur') : __('Belum diatur'), $absensi ? null : __('Isi ATTENDANCE_API_URL dan ATTENDANCE_API_TOKEN.'));

        $tls = (bool) config('attendance.verify_tls');
        $checks[] = $this->check(__('Verifikasi TLS Absensi'), $tls ? 'ok' : 'bad', $tls ? __('Aktif') : __('Mati'), $tls ? null : __('Jangan dimatikan di produksi.'));

        return $checks;
    }

    private function server(): array
    {
        $checks = [];

        $php = PHP_VERSION;
        $checks[] = $this->check('PHP', version_compare($php, '8.4.0', '>=') ? 'ok' : 'bad', $php, __('Aplikasi butuh PHP 8.4 atau lebih baru.'));

        $upload = $this->iniBytes((string) ini_get('upload_max_filesize'));
        $post = $this->iniBytes((string) ini_get('post_max_size'));
        $limits = $upload >= 8 * 1024 ** 2 && $post >= 10 * 1024 ** 2;
        $checks[] = $this->check(__('Batas unggah'), $limits ? 'ok' : 'warn', __('Unggah :up, POST :post', ['up' => Number::fileSize($upload), 'post' => Number::fileSize($post)]), $limits ? null : __('Minimal upload_max_filesize 8M dan post_max_size 10M untuk foto.'));

        $checks[] = $this->check(__('Memori PHP'), 'info', (string) ini_get('memory_limit'), null);

        $missing = array_values(array_filter(['gd', 'pdo_mysql', 'zip', 'fileinfo', 'mbstring', 'openssl'], fn (string $ext) => ! extension_loaded($ext)));
        $checks[] = $this->check(__('Ekstensi PHP'), $missing ? 'bad' : 'ok', $missing ? __('Kurang: :list', ['list' => implode(', ', $missing)]) : __('Lengkap'), null);

        $opcache = (bool) ini_get('opcache.enable') && function_exists('opcache_get_status');
        $checks[] = $this->check('OPcache', $opcache ? 'ok' : 'info', $opcache ? __('Aktif') : __('Tidak aktif'), $opcache ? null : __('Opsional, tapi mempercepat halaman.'));

        $checks[] = $this->check(__('Masa sesi'), 'info', __(':n menit tidak aktif', ['n' => (int) config('session.lifetime')]), null);

        return $checks;
    }

    /** @return array{label: string, status: string, value: string, detail: ?string} */
    private function check(string $label, string $status, string $value, ?string $detail): array
    {
        return ['label' => $label, 'status' => $status, 'value' => $value, 'detail' => $detail];
    }

    private function databaseSize(): string
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return __('Tidak tersedia');
        }
        $bytes = DB::selectOne('SELECT COALESCE(SUM(data_length + index_length), 0) AS size FROM information_schema.tables WHERE table_schema = DATABASE()')->size;

        return Number::fileSize((int) $bytes, 1);
    }

    private function databaseVersion(): string
    {
        return DB::connection()->getDriverName() === 'mysql'
            ? 'MySQL '.DB::selectOne('SELECT VERSION() AS v')->v
            : DB::connection()->getDriverName();
    }

    /** Jumlah error dalam 24 jam terakhir dari laravel.log (hanya 2 MB terakhir dibaca). */
    private function errorsLastDay(): int
    {
        $path = storage_path('logs/laravel.log');
        if (! is_file($path)) {
            return 0;
        }

        $size = filesize($path);
        $handle = fopen($path, 'r');
        fseek($handle, max(0, $size - 2_000_000));
        $chunk = (string) stream_get_contents($handle);
        fclose($handle);

        $since = now()->subDay()->getTimestamp();
        $count = 0;
        foreach (explode("\n", $chunk) as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\].*\.ERROR:/', $line, $match) && strtotime($match[1]) >= $since) {
                $count++;
            }
        }

        return $count;
    }

    /** Ukuran dari php.ini (mis. 8M, 1G) jadi bytes. */
    private function iniBytes(string $value): int
    {
        $number = (int) $value;

        return match (strtoupper(substr(trim($value), -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
