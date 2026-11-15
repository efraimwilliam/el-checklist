<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\GuestRequest;
use App\Models\User;

/**
 * Hak atas permintaan tamu (selalu dicek di server).
 * - Lihat: Admin / HR / GM semua; lainnya yang dicatatnya sendiri atau yang diteruskan ke departemennya.
 * - Ambil / selesaikan: anggota departemen tujuan, HR, Administrator (GM hanya melihat); yang sudah mengambil bisa menyelesaikan.
 * - Lepas: yang mengambil, HOD departemen tujuan, HR, Administrator.
 * - Hapus: pencatatnya selama masih Baru (salah ketik), atau Administrator.
 */
class GuestRequestPolicy
{
    public function before(User $user): ?bool
    {
        return \Illuminate\Support\Facades\Gate::forUser($user)->allows('guest-requests') ? null : false;
    }

    public function view(User $user, GuestRequest $request): bool
    {
        return $user->role->seesAllDepartments() || $request->requested_by === $user->id || $request->isForUser($user);
    }

    public function claim(User $user, GuestRequest $request): bool
    {
        return $request->status === GuestRequest::STATUS_NEW && $this->canHandle($user, $request);
    }

    public function complete(User $user, GuestRequest $request): bool
    {
        return ! $request->isDone() && ($request->claimed_by === $user->id || ($request->claimed_by === null && $this->canHandle($user, $request)) || $user->role->overridesReview() || ($user->role === Role::Hod && $request->isForUser($user)));
    }

    public function release(User $user, GuestRequest $request): bool
    {
        return $request->status === GuestRequest::STATUS_CLAIMED
            && ($request->claimed_by === $user->id || $user->role->overridesReview() || ($user->role === Role::Hod && $request->isForUser($user)));
    }

    public function delete(User $user, GuestRequest $request): bool
    {
        return $user->isAdmin() || ($request->requested_by === $user->id && $request->status === GuestRequest::STATUS_NEW);
    }

    private function canHandle(User $user, GuestRequest $request): bool
    {
        return $user->role->overridesReview() || ($user->role !== Role::Gm && $request->isForUser($user));
    }
}
