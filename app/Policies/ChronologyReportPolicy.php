<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\ChronologyReport;
use App\Models\User;

/**
 * Hak atas Chronology Report, selalu dicek di server.
 * - Lihat: penulis, HOD departemen penulis, HR, GM, Administrator.
 * - Ubah / finalisasi: penulis (atau Administrator) selama masih draft; setelah final terkunci.
 * - Buka kembali yang final: HR / Administrator. Hapus: penulis (draft) dan Administrator.
 */
class ChronologyReportPolicy
{
    public function before(User $user): ?bool
    {
        return $user->canUseApp() ? null : false;
    }

    public function view(User $user, ChronologyReport $report): bool
    {
        return $user->role->seesAllDepartments()
            || $report->author_id === $user->id
            || ($user->role === Role::Hod && $report->department_id !== null && $user->canAccessDepartment($report->department_id));
    }

    public function update(User $user, ChronologyReport $report): bool
    {
        return ! $report->isFinal() && ($report->author_id === $user->id || $user->isAdmin());
    }

    public function reopen(User $user, ChronologyReport $report): bool
    {
        return $report->isFinal() && $user->role->overridesReview();
    }

    public function delete(User $user, ChronologyReport $report): bool
    {
        return $user->isAdmin() || (! $report->isFinal() && $report->author_id === $user->id);
    }
}
