<?php

namespace App\Console\Commands;

use App\Checklists\ChecklistSettings;
use App\Models\User;
use App\Push\AppPushSender;
use App\Push\FcmSender;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Memeriksa kenapa notifikasi aplikasi Android tidak masuk ke HP seseorang: berkas Firebase, HP yang terdaftar (token login + token
 * notifikasi), batas login, saklar fitur; --kirim mengirim satu notifikasi uji dan menampilkan jawaban Firebase.
 */
class CheckAppPush extends Command
{
    protected $signature = 'aplikasi:cek-notif {username : Username pegawai (Absensi)} {--kirim : Kirim notifikasi uji ke HP-nya}';

    protected $description = 'Cek kenapa notifikasi aplikasi Android tidak masuk (dan kirim uji)';

    public function handle(AppPushSender $sender): int
    {
        $path = (string) config('services.fcm.credentials');
        $json = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($json) || ! isset($json['project_id'], $json['private_key'])) {
            $this->error("✗ Berkas Firebase tidak ada / tidak bisa dibaca: {$path}");
            $this->line('  Salin service-account.json ke lokasi itu (DEPLOY.md 13a), pemilik www-data, izin 600.');
        } else {
            $this->info("✓ Berkas Firebase ada (proyek {$json['project_id']}).");
        }

        $settings = app(ChecklistSettings::class);
        foreach (['kendala' => 'Kendala', 'permintaan' => 'Permintaan tamu', 'obrolan' => 'Obrolan'] as $key => $label) {
            $this->line(($settings->featureEnabled($key) ? '✓ ' : '✗ ').$label.($settings->featureEnabled($key) ? ' menyala' : ' MATI (tidak ada notifikasinya)'));
        }

        $user = User::whereHas('employee', fn ($q) => $q->where('username', mb_strtolower((string) $this->argument('username'))))->first();
        if (! $user) {
            $this->error('✗ Pengguna dengan username itu tidak ditemukan.');

            return self::FAILURE;
        }
        $this->line("Pengguna: {$user->name()} (".($user->canUseApp() ? 'akses aktif' : 'AKSES TIDAK AKTIF').')');

        $limit = (int) config('sanctum.expiration');
        $tokens = PersonalAccessToken::where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id)->latest()->get();
        if ($tokens->isEmpty()) {
            $this->error('✗ Belum ada HP yang login ke aplikasi dengan akun ini.');

            return self::FAILURE;
        }
        $ready = collect();
        foreach ($tokens as $token) {
            $expired = $token->created_at->lte(now()->subMinutes($limit));
            $state = match (true) {
                $expired => 'login KEDALUWARSA (lebih dari '.intdiv($limit, 60).' jam) — buka aplikasi & login lagi',
                ! $token->fcm_token => 'tanpa token notifikasi (login lama di HP yang sama, atau izin notifikasi ditolak — buka aplikasi)',
                default => 'siap menerima notifikasi',
            };
            $this->line(sprintf('  %s %s, login %s: %s', ! $expired && $token->fcm_token ? '✓' : '✗', $token->name, $token->created_at->diffForHumans(), $state));
            if (! $expired && $token->fcm_token) {
                $ready->push($token);
            }
        }

        if ($this->option('kirim')) {
            foreach ($ready as $token) {
                $result = $sender->send($token->fcm_token, ['title' => 'Uji notifikasi', 'body' => 'Notifikasi eL Checklist berfungsi.', 'data' => ['type' => 'uji', 'id' => '0'], 'tag' => 'uji']);
                $error = $sender instanceof FcmSender ? $sender->lastError : null;
                $this->line("  Kirim ke {$token->name}: ".match ($result) {
                    AppPushSender::SENT => '✓ diterima Firebase (cek HP)',
                    AppPushSender::GONE => '✗ token tidak berlaku — buka aplikasi lagi',
                    default => '✗ gagal'.($error ? ": {$error}" : ''),
                });
                if ($result === AppPushSender::GONE) {
                    $token->forceFill(['fcm_token' => null])->save();
                }
            }
            if ($ready->isEmpty()) {
                $this->warn('Tidak ada HP yang siap; tidak ada yang dikirim.');
            }
        }

        return $ready->isEmpty() ? self::FAILURE : self::SUCCESS;
    }
}
