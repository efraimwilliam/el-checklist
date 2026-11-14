<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\RoomIssue;
use App\Models\User;

/**
 * Hak atas kendala, selalu dicek di server. "Tugasnya" = kendala diteruskan ke departemennya, jabatan checklistnya, atau dirinya
 * (RoomIssue::isForUser); HOD memegang semua tujuan di departemennya.
 * - Lihat: Admin / HR / GM semua; lainnya yang dilaporkan sendiri atau yang menjadi tugasnya.
 * - Mengambil (`claim`): yang kendalanya diteruskan kepadanya, HR, Administrator; GM hanya melihat.
 * - Mengisi status Done / Ongoing / Pending (`work`): hanya setelah diambil, oleh yang mengambil, HOD departemennya, HR, Administrator.
 * - Kendala yang sudah selesai terkunci; hanya HR / Administrator yang bisa membukanya kembali (`reopen`).
 * - Menghapus kendala (`delete`): hanya Administrator; penghapusan dicatat lengkap di Log aplikasi.
 */
class RoomIssuePolicy
{
    public function before(User $user): ?bool
    {
        return $user->canUseApp() ? null : false;
    }

    public function delete(User $user, RoomIssue $issue): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, RoomIssue $issue): bool
    {
        return $user->role->seesAllDepartments()
            || $issue->reported_by === $user->id
            || $issue->isForUser($user)
            || $issue->updates()->where('user_id', $user->id)->exists();
    }

    /** Mengisi status pekerjaan (Done / Ongoing / Pending): setelah diambil, hanya yang mengambil, HOD departemennya, HR, Administrator. */
    public function work(User $user, RoomIssue $issue): bool
    {
        if ($issue->isDone() || $issue->claimed_by === null) {
            return false;
        }

        return $issue->claimed_by === $user->id
            || $user->role->overridesReview()
            || ($user->role === Role::Hod && $issue->isForUser($user));
    }

    /** Mengambil kendala: boleh bila bisa mengerjakannya dan belum ada yang mengambil. */
    public function claim(User $user, RoomIssue $issue): bool
    {
        return ! $issue->isDone() && $issue->claimed_by === null
            && ($user->role->overridesReview() || ($user->role !== Role::Gm && $issue->isForUser($user)));
    }

    /** Melepas pengambilan: yang mengambil, HOD departemen penanggung jawab, HR, Administrator. */
    public function release(User $user, RoomIssue $issue): bool
    {
        if ($issue->isDone() || $issue->claimed_by === null) {
            return false;
        }

        return $issue->claimed_by === $user->id
            || $user->role->overridesReview()
            || ($user->role === Role::Hod && $issue->isForUser($user));
    }

    public function reopen(User $user, RoomIssue $issue): bool
    {
        return $issue->isDone() && $user->role->overridesReview();
    }
}
