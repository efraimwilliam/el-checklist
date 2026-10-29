<?php

namespace App\Policies;

use App\Enums\ChecklistStatus;
use App\Models\Checklist;
use App\Models\User;

/** Hak atas checklist selalu dicek di server: pemilik, HOD departemen yang ditugaskan, atau HR / GM / Administrator. */
class ChecklistPolicy
{
    public function before(User $user): ?bool
    {
        return $user->canUseApp() ? null : false;
    }

    public function view(User $user, Checklist $checklist): bool
    {
        return $this->owns($user, $checklist)
            || ($user->role->reviews() && $user->canAccessDepartment($checklist->department_id));
    }

    /** Hanya pemilik, selama belum dikirim atau dikembalikan. */
    public function update(User $user, Checklist $checklist): bool
    {
        return $this->owns($user, $checklist) && $checklist->status->editable();
    }

    /** Tidak pernah checklist sendiri. */
    public function review(User $user, Checklist $checklist): bool
    {
        return $checklist->status === ChecklistStatus::Submitted
            && ! $this->owns($user, $checklist)
            && $user->role->reviews()
            && $user->canAccessDepartment($checklist->department_id);
    }

    private function owns(User $user, Checklist $checklist): bool
    {
        return $checklist->employee_id === $user->employee_id;
    }
}
