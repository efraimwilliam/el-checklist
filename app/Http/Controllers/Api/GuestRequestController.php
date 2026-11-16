<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\GuestRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Permintaan tamu untuk aplikasi Android: hanya baca (daftar + pilihan form). Mencatat, ambil, selesai, lepas, dan hapus
 * memakai `App\Http\Controllers\GuestRequestController` yang sama dengan web (JSON bila dipanggil lewat /api).
 */
class GuestRequestController extends Controller
{
    // shortcut: daftar Selesai hanya 60 terbaru; tambahkan paginasi bila perlu menelusuri lebih jauh dari HP.
    private const LIMIT = 60;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('guest-requests');
        $user = $request->user();
        $done = $request->query('status') === 'selesai';

        // Sama dengan web: yang menunggu paling lama di atas (mendesak dulu); yang selesai terbaru di atas.
        $items = GuestRequest::visibleTo($user)->with(['room', 'department', 'requester.employee', 'claimer.employee', 'completer.employee'])
            ->where('status', $done ? '=' : '!=', GuestRequest::STATUS_DONE)
            ->when(! $done, fn ($q) => $q->orderByRaw("case when priority = 'mendesak' then 0 else 1 end")->orderBy('created_at'))
            ->when($done, fn ($q) => $q->orderByDesc('completed_at'))
            ->limit(self::LIMIT)->get();

        return response()->json([
            'requests' => $items->map(fn (GuestRequest $item) => $this->row($item, $user))->values(),
            'new_for_me' => $user->newGuestRequestCount(),
        ]);
    }

    public function form(): JsonResponse
    {
        Gate::authorize('guest-requests');

        return response()->json([
            'kinds' => GuestRequest::kinds()->active()->map(fn ($kind) => ['key' => $kind->key, 'label' => __($kind->label), 'has_quantity' => (bool) $kind->has_quantity])->values(),
            'departments' => Department::where('is_active', true)->orderBy('sort')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(GuestRequest $item, User $user): array
    {
        return [
            'id' => $item->id,
            'room' => $item->room?->number,
            'label' => $item->label(),
            'note' => $item->note,
            'priority' => $item->priority,
            'status' => $item->status,
            'status_label' => GuestRequest::statusLabels()[$item->status] ?? $item->status,
            'department' => $item->department?->name,
            'requester' => $item->requester?->name(),
            'claimer' => $item->claimer?->name(),
            'completer' => $item->completer?->name(),
            'created_at' => $item->created_at?->toIso8601String(),
            'completed_at' => $item->completed_at?->toIso8601String(),
            'can' => collect(['claim', 'complete', 'release', 'delete'])->mapWithKeys(fn ($ability) => [$ability => $user->can($ability, $item)]),
        ];
    }
}
