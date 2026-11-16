<?php

namespace App\Http\Controllers\Api;

use App\Attendance\AttendanceApiService;
use App\Audit\AuditLogger;
use App\Http\Controllers\Auth\AttendanceLoginController;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        ];
    }
}
