<?php

namespace App\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Klien API Sistem Absensi. PIN dan password hanya diteruskan ke API; tidak pernah dicatat atau disimpan.
 * Permintaan login tidak diulang otomatis (retry bisa menghabiskan jatah salah PIN pegawai).
 */
class AttendanceApiService
{
    private const REFUSALS = [
        'invalid_credentials' => AuthOutcome::InvalidCredentials,
        'locked' => AuthOutcome::Locked,
        'pin_not_set' => AuthOutcome::PinNotSet,
        'pin_disabled' => AuthOutcome::PinDisabled,
        'inactive' => AuthOutcome::Inactive,
        'password_must_change' => AuthOutcome::PasswordMustChange,
    ];

    public function configured(): bool
    {
        return filled(config('attendance.url')) && filled(config('attendance.token'));
    }

    public function authenticate(string $username, string $pin): AuthResult
    {
        return $this->attempt(config('attendance.paths.login'), ['username' => $username, 'pin' => $pin]);
    }

    /** Password akun dashboard (Administrator, Admin HR, HOD). Akun lain ditolak Absensi dengan invalid_credentials. */
    public function authenticatePassword(string $username, string $password): AuthResult
    {
        return $this->attempt(config('attendance.paths.login_password'), ['username' => $username, 'password' => $password]);
    }

    /** @param  array<string, string>  $payload */
    private function attempt(string $path, array $payload): AuthResult
    {
        if (! $this->configured()) {
            return $this->fail(AuthOutcome::NotConfigured);
        }

        try {
            $response = $this->client()->post($path, $payload);
        } catch (ConnectionException $e) {
            // Pesan exception Guzzle tidak memuat isi permintaan; tetap hanya kelasnya yang dicatat.
            $timedOut = str_contains(strtolower($e->getMessage()), 'timed out');

            return $this->fail($timedOut ? AuthOutcome::Timeout : AuthOutcome::Unavailable, ['error' => $e::class]);
        }

        // 422 = Absensi menolak isian (mis. PIN lebih dari 6 karakter): bukan gangguan, jadi dihitung sebagai salah kredensial.
        if ($response->status() === 422) {
            return new AuthResult(AuthOutcome::InvalidCredentials);
        }

        if (! $response->ok()) {
            return $this->fail(AuthOutcome::Unavailable, ['status' => $response->status()]);
        }

        $body = $response->json();
        if (! is_array($body) || ! is_bool($body['authenticated'] ?? null)) {
            return $this->fail(AuthOutcome::Malformed);
        }

        if (! $body['authenticated']) {
            $outcome = self::REFUSALS[$body['reason'] ?? ''] ?? null;

            return $outcome
                ? new AuthResult($outcome, retryAfter: isset($body['retry_after']) ? (int) $body['retry_after'] : null)
                : $this->fail(AuthOutcome::Malformed);
        }

        $employee = $this->normalizeEmployee($body['employee'] ?? null);

        return $employee ? new AuthResult(AuthOutcome::Authenticated, $employee) : $this->fail(AuthOutcome::Malformed);
    }

    /**
     * Ubah username / password dashboard akun di Sistem Absensi. PIN atau password pengubah ikut dikirim untuk diperiksa di sana
     * (tidak disimpan di sini). Respons 422 (validasi) dikembalikan sebagai alasan `validation`.
     *
     * @param  array<string, string>  $payload
     * @return array{ok: bool, reason?: string, message?: string, retry_after?: int, employee?: array{id: int, username: string, name: string, department_id: ?int, position: ?string, is_active: bool, photo_version: ?string}}
     *
     * @throws AttendanceUnavailable
     */
    public function updateAccount(array $payload): array
    {
        if (! $this->configured()) {
            throw new AttendanceUnavailable(__('Koneksi Sistem Absensi belum diatur (ATTENDANCE_API_URL & ATTENDANCE_API_TOKEN).'));
        }

        try {
            $response = $this->client()->patch(config('attendance.paths.account'), $payload);
        } catch (ConnectionException) {
            throw new AttendanceUnavailable(__('Sistem Absensi tidak dapat dihubungi.'));
        }

        if ($response->status() === 422) {
            return ['ok' => false, 'reason' => 'validation'];
        }
        if (! $response->ok()) {
            throw new AttendanceUnavailable(__('Sistem Absensi menolak permintaan (kode :code).', ['code' => $response->status()]));
        }

        $body = $response->json();
        if (! is_array($body) || ! is_bool($body['ok'] ?? null)) {
            throw new AttendanceUnavailable(__('Respons Sistem Absensi tidak dikenali.'));
        }
        if ($body['ok']) {
            $employee = $this->normalizeEmployee($body['employee'] ?? null);
            if (! $employee) {
                throw new AttendanceUnavailable(__('Respons Sistem Absensi tidak dikenali.'));
            }

            return ['ok' => true, 'employee' => $employee];
        }

        return array_filter([
            'ok' => false,
            'reason' => is_string($body['reason'] ?? null) ? $body['reason'] : 'unknown',
            'message' => is_string($body['message'] ?? null) ? $body['message'] : null,
            'retry_after' => isset($body['retry_after']) ? (int) $body['retry_after'] : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @return list<array{id: int, username: string, name: string, department_id: ?int, position: ?string, is_active: bool, photo_version: ?string}>
     *
     * @throws AttendanceUnavailable
     */
    public function employees(): array
    {
        return array_values(array_filter(array_map($this->normalizeEmployee(...), $this->fetch('employees'))));
    }

    /**
     * @return list<array{id: int, code: ?string, name: string}>
     *
     * @throws AttendanceUnavailable
     */
    public function departments(): array
    {
        $rows = [];
        foreach ($this->fetch('departments') as $row) {
            if (is_array($row) && is_int($row['id'] ?? null) && filled($row['name'] ?? null)) {
                $rows[] = ['id' => $row['id'], 'code' => filled($row['code'] ?? null) ? (string) $row['code'] : null, 'name' => (string) $row['name']];
            }
        }

        return $rows;
    }

    /**
     * Status jadwal semua pegawai aktif pada satu tanggal, dikunci id Absensi.
     * work = ada shift, off = libur biasa, leave = izin / cuti / sakit, unscheduled = belum ada jadwal.
     *
     * @return array<int, array{status: string, code: ?string}>
     *
     * @throws AttendanceUnavailable
     */
    public function schedules(CarbonImmutable $date): array
    {
        $result = [];
        foreach ($this->fetch('schedules', ['date' => $date->toDateString()]) as $row) {
            if (is_array($row) && is_int($row['employee_id'] ?? null) && in_array($row['status'] ?? null, ['work', 'off', 'leave', 'unscheduled'], true)) {
                $result[$row['employee_id']] = ['status' => $row['status'], 'code' => filled($row['code'] ?? null) ? (string) $row['code'] : null];
            }
        }

        return $result;
    }

    /**
     * Foto profil satu pegawai (isi JPEG mentah dari Absensi); null bila pegawai tidak punya foto (404).
     *
     * @throws AttendanceUnavailable
     */
    public function employeePhoto(int $externalId): ?string
    {
        if (! $this->configured()) {
            throw new AttendanceUnavailable(__('Koneksi Sistem Absensi belum diatur (ATTENDANCE_API_URL & ATTENDANCE_API_TOKEN).'));
        }

        try {
            $response = $this->client()->accept('image/jpeg')->get(str_replace('{id}', (string) $externalId, (string) config('attendance.paths.employee_photo')));
        } catch (ConnectionException) {
            throw new AttendanceUnavailable(__('Sistem Absensi tidak dapat dihubungi.'));
        }
        if ($response->notFound()) {
            return null;
        }
        if (! $response->ok()) {
            throw new AttendanceUnavailable(__('Sistem Absensi menolak permintaan (kode :code).', ['code' => $response->status()]));
        }

        return $response->body();
    }

    /** @return list<mixed> */
    private function fetch(string $resource, array $query = []): array
    {
        if (! $this->configured()) {
            throw new AttendanceUnavailable(__('Koneksi Sistem Absensi belum diatur (ATTENDANCE_API_URL & ATTENDANCE_API_TOKEN).'));
        }

        try {
            $response = $this->client()->get(config("attendance.paths.{$resource}"), $query);
        } catch (ConnectionException) {
            throw new AttendanceUnavailable(__('Sistem Absensi tidak dapat dihubungi.'));
        }
        if (! $response->ok()) {
            throw new AttendanceUnavailable(__('Sistem Absensi menolak permintaan (kode :code).', ['code' => $response->status()]));
        }
        $data = $response->json('data');
        if (! is_array($data) || ! array_is_list($data)) {
            throw new AttendanceUnavailable(__('Respons Sistem Absensi tidak dikenali.'));
        }

        return $data;
    }

    /** @return array{id: int, username: string, name: string, department_id: ?int, position: ?string, is_active: bool, photo_version: ?string}|null */
    private function normalizeEmployee(mixed $row): ?array
    {
        if (! is_array($row) || ! is_int($row['id'] ?? null) || ! filled($row['username'] ?? null) || ! filled($row['name'] ?? null) || ! is_bool($row['is_active'] ?? null)) {
            return null;
        }

        return [
            'id' => $row['id'],
            'username' => mb_strtolower((string) $row['username']),
            'name' => (string) $row['name'],
            'department_id' => is_int($row['department_id'] ?? null) ? $row['department_id'] : null,
            'position' => filled($row['position'] ?? null) ? (string) $row['position'] : null,
            'is_active' => $row['is_active'],
            'photo_version' => is_string($row['photo_version'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $row['photo_version']) ? $row['photo_version'] : null,
        ];
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(config('attendance.url'))
            ->withToken((string) config('attendance.token'))
            ->withHeaders(config('attendance.headers', []))
            ->acceptJson()
            ->connectTimeout(config('attendance.connect_timeout'))
            ->timeout(config('attendance.timeout'))
            ->withOptions(['verify' => config('attendance.verify_tls')]);
    }

    /** @param  array<string, mixed>  $context */
    private function fail(AuthOutcome $outcome, array $context = []): AuthResult
    {
        Log::warning('Attendance API problem', ['outcome' => $outcome->value, ...$context]);

        return new AuthResult($outcome);
    }
}
