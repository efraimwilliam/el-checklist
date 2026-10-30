<?php

namespace App\Policies;

use App\Enums\ChecklistStatus;
use App\Enums\Role;
use App\Models\Checklist;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;

/**
 * Hak atas checklist, selalu dicek di server.
 * - Staff: hanya miliknya sendiri. HOD: staff di departemen yang ditugaskan. HR / Administrator: semua departemen. GM: hanya melihat dan mereview.
 * - Setelah direview, isian terkunci; hanya HR / Administrator yang masih bisa mengubah atau menghapus.
 */
class ChecklistPolicy
{
    public function before(User $user): ?bool
    {
        return $user->canUseApp() ? null : false;
    }

    public function view(User $user, Checklist $checklist): bool
    {
        return $this->owns($user, $checklist)
            || $user->role->seesAllDepartments()
            || ($user->role === Role::Hod && $user->canAccessDepartment($checklist->department_id));
    }

    /** Membuat checklist baru: jabatan harus di departemen yang sama dengan pegawainya. */
    public function fill(User $user, Employee $employee, Position $position): bool
    {
        if ($employee->department_id !== $position->department_id || ! $employee->is_active) {
            return false;
        }

        return match ($user->role) {
            Role::Admin, Role::Hr => true,
            Role::Hod => $user->canAccessDepartment($position->department_id),
            Role::Staff => $employee->id === $user->employee_id && $position->id === ($employee->checklist_position_id ?? $user->position_id),
            Role::Gm => false,
        };
    }

    /** Mengisi checklist (isian, catatan, foto). Hari yang akan datang hanya kalau Administrator mengaktifkannya. */
    public function update(User $user, Checklist $checklist): bool
    {
        if ($checklist->date->isFuture() && ! app(\App\Checklists\ChecklistSettings::class)->futureFillAllowed()) {
            return false;
        }

        return $this->canChange($user, $checklist);
    }

    /** Mengatur tugas checklist (Atur tugas hari ini): juga untuk hari yang akan datang. */
    public function plan(User $user, Checklist $checklist): bool
    {
        return $this->canChange($user, $checklist);
    }

    /** Setelah direview HOD atau GM / HR, hanya HR / Administrator (HOD bisa membatalkan review HOD dulu). */
    private function canChange(User $user, Checklist $checklist): bool
    {
        if ($checklist->isReviewed() || $checklist->isHodReviewed()) {
            return $user->role->overridesReview();
        }

        return match ($user->role) {
            Role::Admin, Role::Hr => true,
            Role::Hod => $user->canAccessDepartment($checklist->department_id),
            Role::Staff => $this->owns($user, $checklist),
            Role::Gm => false,
        };
    }

    /**
     * Verifikasi, hanya bila checklist lengkap:
     * - HOD (departemennya dan miliknya sendiri): dari "Belum direview" -> "Direview oleh HOD".
     * - GM, HR, Administrator: checklist orang lain, dari "Belum direview" atau "Direview oleh HOD" -> "Direview oleh GM/HR" (terkunci).
     */
    public function review(User $user, Checklist $checklist): bool
    {
        if ($checklist->isReviewed() || ! $checklist->isComplete()) {
            return false;
        }

        if ($user->role->reviews()) {
            return ! $this->owns($user, $checklist);
        }

        return $user->role === Role::Hod
            && $checklist->status === ChecklistStatus::Saved
            && ($this->owns($user, $checklist) || $user->canAccessDepartment($checklist->department_id));
    }

    /** HOD membatalkan verifikasinya sendiri (atau di departemennya) supaya isian bisa diubah lagi. HR / Administrator juga boleh. */
    public function unreview(User $user, Checklist $checklist): bool
    {
        if ($checklist->status !== ChecklistStatus::HodReviewed) {
            return false;
        }

        return $user->role->overridesReview()
            || ($user->role === Role::Hod && ($this->owns($user, $checklist) || $user->canAccessDepartment($checklist->department_id)));
    }

    public function delete(User $user, Checklist $checklist): bool
    {
        return $user->role->overridesReview();
    }

    private function owns(User $user, Checklist $checklist): bool
    {
        return $checklist->employee_id === $user->employee_id;
    }
}
