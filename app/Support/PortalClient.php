<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Login lewat Portal SSO (OAuth2 authorization code, Laravel Passport di portal).
 *
 * Alur: /sso/login → portal /oauth/authorize → /sso/callback?code → tukar code jadi token
 * → GET portal /api/user (siapa yang login, dan masih punya akses ke sistem ini).
 * Semua panggilan dari server ini; browser hanya diarahkan ke portal.
 */
class PortalClient
{
    public const MODE_REQUIRED = 'required';

    public const MODE_BOTH = 'both';

    public const MODE_LOCAL = 'local';

    /** Koneksi ke portal sudah diisi di .env (dan tidak dimatikan lewat SSO_ENABLED=false). */
    public static function configured(): bool
    {
        return (bool) config('services.sso.enabled')
            && self::url() !== ''
            && (string) config('services.sso.client_id') !== ''
            && (string) config('services.sso.client_secret') !== '';
    }

    /**
     * Mode login alamat sistem ini, diatur di portal (Kelola Sistem): required, both, atau local.
     * Dibaca dari portal dan disimpan 1 menit; portal tidak terjangkau = pakai nilai terakhir
     * yang diketahui, atau required bila belum pernah berhasil dibaca.
     */
    public static function mode(): string
    {
        if (! self::configured()) {
            return self::MODE_LOCAL;
        }

        if (app()->bound('sso.mode')) {
            return app('sso.mode');
        }

        $mode = Cache::get('sso.mode.fresh');

        if ($mode === null) {
            $fetched = self::fetchMode();
            $mode = $fetched ?? Cache::get('sso.mode.last') ?? self::MODE_REQUIRED;

            Cache::put('sso.mode.fresh', $mode, $fetched ? 60 : 15);

            if ($fetched) {
                Cache::forever('sso.mode.last', $fetched);
            }
        }

        app()->instance('sso.mode', $mode);

        return $mode;
    }

    /** SSO dipakai di sistem ini (mode required atau both). */
    public static function enabled(): bool
    {
        return self::mode() !== self::MODE_LOCAL;
    }

    /** Alamat sistem ini otomatis diarahkan ke portal; login username + PIN tidak diterima. */
    public static function requiresPortal(): bool
    {
        return self::mode() === self::MODE_REQUIRED;
    }

    /** Form login username + PIN boleh tampil dan diterima. */
    public static function allowsPasswordLogin(): bool
    {
        return self::mode() !== self::MODE_REQUIRED;
    }

    /** Sesi yang sedang berjalan berasal dari login lewat portal (bukan username + PIN). */
    public static function viaPortal(): bool
    {
        return self::enabled() && is_string(session('sso.token'));
    }

    public static function url(): string
    {
        return (string) config('services.sso.portal_url');
    }

    /** Beranda portal untuk memilih sistem lain. ?beranda=1 melewati aturan "satu sistem = langsung dibuka". */
    public static function homeUrl(): string
    {
        return self::url().'/?beranda=1';
    }

    /** Keluar dari portal dan semua sistem (tombol Keluar di sistem ini). */
    public static function logoutUrl(): string
    {
        return self::url().'/keluar';
    }

    public static function authorizeUrl(string $state): string
    {
        return self::url().'/oauth/authorize?'.http_build_query([
            'client_id' => config('services.sso.client_id'),
            'redirect_uri' => route('sso.callback'),
            'response_type' => 'code',
            'scope' => '',
            'state' => $state,
        ]);
    }

    /**
     * Tukar kode dari portal menjadi access token.
     *
     * @throws ConnectionException portal tidak bisa dihubungi
     * @throws RuntimeException portal menolak kode
     */
    public static function exchangeCode(string $code): string
    {
        $response = Http::asForm()->acceptJson()->timeout(10)->post(self::url().'/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => config('services.sso.client_id'),
            'client_secret' => config('services.sso.client_secret'),
            'redirect_uri' => route('sso.callback'),
            'code' => $code,
        ]);

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException('Portal menolak kode login (HTTP '.$response->status().').');
        }

        return $token;
    }

    /**
     * Data pengguna portal untuk token ini; null bila token tidak berlaku lagi
     * (keluar dari portal, akun nonaktif, tidak aktif terlalu lama, atau akses ke sistem ini dicabut).
     *
     * @return array{id: int, username: string, name: string, email: ?string}|null
     *
     * @throws ConnectionException portal tidak bisa dihubungi
     */
    public static function user(string $token): ?array
    {
        $response = Http::withToken($token)->acceptJson()->timeout(5)->get(self::url().'/api/user');

        if (in_array($response->status(), [401, 403], true)) {
            return null;
        }

        if (! $response->successful() || ! is_numeric($response->json('id')) || ! is_string($response->json('username'))) {
            throw new RuntimeException('Jawaban portal tidak dikenali (HTTP '.$response->status().').');
        }

        return [
            'id' => (int) $response->json('id'),
            'username' => strtolower($response->json('username')),
            'name' => (string) $response->json('name'),
            'email' => $response->json('email'),
        ];
    }

    /**
     * Lapor aktivitas pengguna ke portal, supaya batas "tidak aktif" dihitung dari semua sistem.
     * Hasil: 'ok', 'ended' (sesi sudah diakhiri portal, mis. idle), atau 'unreachable'.
     */
    public static function reportActivity(string $token): string
    {
        try {
            $response = Http::withToken($token)->acceptJson()->timeout(5)->post(self::url().'/api/aktivitas');
        } catch (Throwable) {
            return 'unreachable';
        }

        if (in_array($response->status(), [401, 403], true)) {
            return 'ended';
        }

        return $response->successful() ? 'ok' : 'unreachable';
    }

    private static function fetchMode(): ?string
    {
        try {
            $response = Http::acceptJson()->timeout(3)
                ->get(self::url().'/api/sso-mode', ['client_id' => config('services.sso.client_id')]);
        } catch (Throwable) {
            return null;
        }

        $mode = $response->successful() ? $response->json('login_mode') : null;

        return in_array($mode, [self::MODE_REQUIRED, self::MODE_BOTH, self::MODE_LOCAL], true) ? $mode : null;
    }
}
