<?php

namespace App\Policies;

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

    /** Mengubah checklist yang sudah ada. Setelah direview hanya HR / Administrator. */
    public function update(User $user, Checklist $checklist): bool
    {
        if ($checklist->isReviewed()) {
            return $user->role->overridesReview();
        }

        return match ($user->role) {
            Role::Admin, Role::Hr => true,
            Role::Hod => $user->canAccessDepartment($checklist->department_id),
            Role::Staff => $this->owns($user, $checklist),
            Role::Gm => false,
        };
    }

    /** GM, HR dan Administrator menandai sudah direview, hanya bila checklist lengkap; tidak pernah checklist sendiri. */
    public function review(User $user, Checklist $checklist): bool
    {
        return ! $checklist->isReviewed() && $user->role->reviews() && ! $this->owns($user, $checklist) && $checklist->isComplete();
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
