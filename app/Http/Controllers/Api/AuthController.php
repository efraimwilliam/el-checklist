<?php

namespace App\Http\Controllers\Api;

use App\Attendance\AttendanceApiService;
use App\Audit\AuditLogger;
use App\Enums\Role;
use App\Http\Controllers\Auth\AttendanceLoginController;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Login aplikasi Android: aturan sama persis dengan form web (`AttendanceLoginController::attempt`), hasilnya token per perangkat.
 * PIN tidak disimpan di server maupun di HP; token berakhir sesuai `sanctum.expiration` dan batas diam (`EnsureActiveToken`).
 */
class AuthController extends Controller
{
    public function login(Request $request, AttendanceApiService $api, AttendanceLoginController $login): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:100'],
            'pin' => ['required', 'string', 'max:255'],
            'device' => ['nullable', 'string', 'max:100'],
        ]);
        $user = $login->attempt($request, $api, $data['username'], $data['pin']);
        if (is_string($user)) {
            return response()->json(['message' => $user], 422);
        }

        $token = $user->createToken($data['device'] ?? 'android');

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String()
                ?? now()->addMinutes((int) config('sanctum.expiration'))->toIso8601String(),
            'user' => $this->profile($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->profile($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        AuditLogger::record('logout');
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    /** Token notifikasi Firebase HP ini; satu HP hanya untuk satu akun (akun lain yang login di HP itu mengambil alih). */
    public function pushToken(Request $request): JsonResponse
    {
        $token = $request->validate(['token' => ['nullable', 'string', 'max:512']])['token'] ?? null;
        $current = $request->user()->currentAccessToken();
        if ($token !== null) {
            PersonalAccessToken::where('fcm_token', $token)->whereKeyNot($current->getKey())->update(['fcm_token' => null]);
        }
        $current->forceFill(['fcm_token' => $token])->save();

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function profile(User $user): array
    {
        $user->loadMissing('employee.department');

        return [
            'id' => $user->id,
            'name' => $user->name(),
            'role' => $user->role->value,
            'department' => $user->employee?->department?->name,
            'position' => $user->employee?->position_title,
            // Menu aplikasi mengikuti saklar fitur dan gate yang sama dengan web.
            'features' => [
                'kendala' => Gate::forUser($user)->allows('room-issues'),
                'permintaan' => Gate::forUser($user)->allows('guest-requests'),
                // Papan Isi checklist tim: HOD / HR / Administrator (bukan Staff, bukan GM).
                'papan' => Gate::forUser($user)->allows('fill-checklists') && $user->role !== Role::Staff,
                'riwayat' => Gate::forUser($user)->allows('view-checklists') || $user->role === Role::Staff,
                'obrolan' => Gate::forUser($user)->allows('chat'),
                'chronology' => Gate::forUser($user)->allows('chronology-reports'),
            ],
        ];
    }
}
