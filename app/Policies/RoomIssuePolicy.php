<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\RoomIssue;
use App\Models\User;

/**
 * Hak atas kendala kamar, selalu dicek di server.
 * - Lihat: Admin / HR / GM semua; lainnya yang dilaporkan sendiri atau ditugaskan ke departemennya.
 * - Mengambil (`claim`): anggota departemen penanggung jawab (termasuk HODnya), HR, Administrator; GM hanya melihat.
 * - Mengisi status Done / Ongoing / Pending (`work`): hanya setelah diambil, oleh yang mengambil, HOD departemennya, HR, Administrator.
 * - Kendala yang sudah selesai terkunci; hanya HR / Administrator yang bisa membukanya kembali (`reopen`).
 */
class RoomIssuePolicy
{
    public function before(User $user): ?bool
    {
        return $user->canUseApp() ? null : false;
    }

    public function view(User $user, RoomIssue $issue): bool
    {
        return $user->role->seesAllDepartments()
            || $issue->reported_by === $user->id
            || $this->inAssignedDepartment($user, $issue)
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
            || ($user->role === Role::Hod && $this->inAssignedDepartment($user, $issue));
    }

    /** Mengambil kendala: boleh bila bisa mengerjakannya dan belum ada yang mengambil. */
    public function claim(User $user, RoomIssue $issue): bool
    {
        return ! $issue->isDone() && $issue->claimed_by === null
            && ($user->role->overridesReview() || ($user->role !== Role::Gm && $this->inAssignedDepartment($user, $issue)));
    }

    /** Melepas pengambilan: yang mengambil, HOD departemen penanggung jawab, HR, Administrator. */
    public function release(User $user, RoomIssue $issue): bool
    {
        if ($issue->isDone() || $issue->claimed_by === null) {
            return false;
        }

        return $issue->claimed_by === $user->id
            || $user->role->overridesReview()
            || ($user->role === Role::Hod && $this->inAssignedDepartment($user, $issue));
    }

    public function reopen(User $user, RoomIssue $issue): bool
    {
        return $issue->isDone() && $user->role->overridesReview();
    }

    private function inAssignedDepartment(User $user, RoomIssue $issue): bool
    {
        return $issue->assigned_department_id !== null && in_array($issue->assigned_department_id, $user->issueDepartmentIds(), true);
    }
}
