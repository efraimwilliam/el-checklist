<?php

namespace App\Chat;

use App\Models\Department;
use App\Models\GuestRequest;
use App\Models\RoomIssue;
use App\Models\RoomIssueUpdate;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Mencatat hasil bacaan ketik bebas (App\Chat\QuickEntry) menjadi permintaan tamu / kendala. Dipakai obrolan web (ChatController) dan
 * bot Telegram (App\Telegram\TelegramHandler), jadi aturannya satu: departemen tiap jenis, kendala serupa digabung.
 */
class QuickRecorder
{
    /**
     * Satu permintaan per jenis yang terbaca. "Lainnya" ke $otherDepartmentId bila diberikan (dan departemen aktif), selain itu ke departemen jenisnya.
     *
     * @param  array<string, mixed>  $read  hasil QuickEntry::read bertipe request dengan kamar yang dikenal
     * @return Collection<int, GuestRequest>
     */
    public function requests(User $user, array $read, string $text, ?int $otherDepartmentId = null): Collection
    {
        $other = Department::where('is_active', true)->whereKey((int) $otherDepartmentId)->value('id') ?? GuestRequest::kinds()->departmentFor('other');

        return collect($read['items'])->map(fn (array $item) => GuestRequest::create([
            'room_id' => $read['room']->id, 'kind' => $item['kind'], 'item_name' => $item['name'] ?? null,
            'quantity' => GuestRequest::kinds()->hasQuantity($item['kind']) ? $item['quantity'] : 1,
            'note' => mb_substr($text, 0, 500), 'priority' => $read['priority'], 'source' => 'chat', 'requested_by' => $user->id,
            'assigned_department_id' => $item['kind'] === 'other' ? $other : GuestRequest::kinds()->departmentFor($item['kind']), 'status' => GuestRequest::STATUS_NEW,
        ]));
    }

    /** Kendala serupa yang belum selesai (kamar + kategori sama) menerima laporan tambahan, bukan kendala ganda. */
    public function issue(User $user, array $read, string $text): RoomIssue
    {
        $roomId = $read['room']->id;
        $categoryId = $read['category']?->id;
        $note = fn (RoomIssue $issue, string $body, string $kind, ?int $related = null) => RoomIssueUpdate::create([
            'room_issue_id' => $issue->id, 'user_id' => $user->id, 'department_id' => $user->checklistDepartmentId(), 'body' => $body, 'kind' => $kind, 'related_issue_id' => $related,
        ]);

        if ($duplicate = RoomIssue::openDuplicate($roomId, null, $categoryId)) {
            $note($duplicate, __('Laporan lagi (:source): :text', ['source' => RoomIssue::sourceLabels()['staff'], 'text' => mb_substr($text, 0, 2000)]), 'laporan');
            $read['priority'] === 'mendesak' && $duplicate->priority !== 'mendesak' ? $duplicate->update(['priority' => 'mendesak']) : $duplicate->touch();

            return $duplicate;
        }

        $issue = RoomIssue::create([
            'room_id' => $roomId, 'category_id' => $categoryId, 'source' => 'staff', 'priority' => $read['priority'],
            'recurrence' => RoomIssue::nextRecurrence($roomId, null, $categoryId), 'description' => mb_substr($text, 0, 2000), 'reported_by' => $user->id,
            'assigned_department_id' => $categoryId ? $read['category']->department_id : null, 'status' => RoomIssue::STATUS_NEW,
        ]);
        if ($issue->recurrence > 1) {
            RoomIssue::atPlace($roomId, null)->where('category_id', $categoryId)->whereKeyNot($issue->id)->orderByDesc('created_at')->first()
                ?->updates()->create(['body' => __('Muncul lagi sebagai kendala #:id (Berulang ke-:n).', ['id' => $issue->id, 'n' => $issue->recurrence]), 'kind' => 'berulang', 'related_issue_id' => $issue->id]);
        }

        return $issue;
    }
}
