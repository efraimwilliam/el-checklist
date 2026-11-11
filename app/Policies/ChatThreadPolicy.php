<?php

namespace App\Policies;

use App\Models\ChatThread;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Hak atas obrolan, selalu dicek di server dan hanya saat fitur dinyalakan.
 * - Baca: peserta; yang berhak melihat kendala / checklist yang ditempeli; HR dan Administrator membaca SEMUA percakapan (termasuk chat pribadi).
 * - Kirim: peserta, atau yang berhak melihat kendala / checklist yang ditempeli (otomatis jadi peserta). HR / Administrator di percakapan yang
 *   bukan miliknya hanya memantau.
 * - Tambah peserta / keluar: hanya grup, oleh pesertanya.
 */
class ChatThreadPolicy
{
    public function before(User $user): ?bool
    {
        return Gate::forUser($user)->allows('chat') ? null : false;
    }

    public function view(User $user, ChatThread $thread): bool
    {
        return $user->role->overridesReview() || $this->canPost($user, $thread);
    }

    public function post(User $user, ChatThread $thread): bool
    {
        return $this->canPost($user, $thread);
    }

    public function invite(User $user, ChatThread $thread): bool
    {
        return $thread->isGroup() && $thread->isParticipant($user);
    }

    public function leave(User $user, ChatThread $thread): bool
    {
        return $thread->isGroup() && $thread->isParticipant($user);
    }

    private function canPost(User $user, ChatThread $thread): bool
    {
        if ($thread->isParticipant($user)) {
            return true;
        }
        $gate = Gate::forUser($user);
        if ($thread->room_issue_id) {
            return $thread->issue !== null && $gate->allows('view', $thread->issue);
        }
        if ($thread->checklist_id) {
            return $thread->checklist !== null && $gate->allows('view', $thread->checklist);
        }

        return false;
    }
}
