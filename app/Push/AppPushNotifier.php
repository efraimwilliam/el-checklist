<?php

namespace App\Push;

use App\Models\GuestRequest;
use App\Models\RoomIssue;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Notifikasi aplikasi Android untuk tugas baru: kendala baru ke yang dituju (`RoomIssue::isForUser`, sama dengan angka menu Kendala)
 * dan permintaan tamu baru ke departemen tujuannya. Dikumpulkan selama satu permintaan HTTP lalu dikirim sesudah jawaban terkirim
 * (`defer`), jadi satu form dengan beberapa jenis permintaan = satu notifikasi per kamar. Tidak dikirim ke pencatatnya sendiri.
 * Isi sengaja singkat (tempat + jenis, tanpa uraian) karena tampil di layar kunci.
 */
final class AppPushNotifier
{
    /** @var array{kendala: list<int>, permintaan: list<int>} */
    private array $queued = ['kendala' => [], 'permintaan' => []];

    public function __construct(private readonly AppPushSender $sender) {}

    public function queue(string $type, int $id): void
    {
        if ($this->queued === ['kendala' => [], 'permintaan' => []]) {
            defer(fn () => $this->flush());
        }
        $this->queued[$type][] = $id;
    }

    /** @return int jumlah notifikasi terkirim */
    public function flush(): int
    {
        [$issueIds, $requestIds] = [$this->queued['kendala'], $this->queued['permintaan']];
        $this->queued = ['kendala' => [], 'permintaan' => []];
        $devices = $this->devices();
        if ($devices->isEmpty()) {
            return 0;
        }

        $sent = 0;
        foreach (RoomIssue::with(['room', 'category'])->whereKey($issueIds)->get() as $issue) {
            $sent += $this->deliver($devices, fn (User $user) => $user->id !== $issue->reported_by && Gate::forUser($user)->allows('room-issues') && $issue->isForUser($user), [
                'title' => __('Kendala baru').' · '.$issue->placeTitle(),
                'body' => collect([$issue->category?->name ?? __('Tanpa kategori'), $issue->priority === 'mendesak' ? __('Mendesak') : null])->filter()->join(' · '),
                'data' => ['type' => 'kendala', 'id' => (string) $issue->id],
                'tag' => 'kendala-'.$issue->id,
            ]);
        }

        // Permintaan dari satu form / pesan: satu notifikasi per kamar + departemen tujuan.
        $groups = GuestRequest::with('room')->whereKey($requestIds)->get()->groupBy(fn (GuestRequest $r) => $r->room_id.'-'.$r->assigned_department_id);
        foreach ($groups as $items) {
            $first = $items->first();
            $sent += $this->deliver($devices, fn (User $user) => $user->id !== $first->requested_by && Gate::forUser($user)->allows('guest-requests') && $first->isForUser($user), [
                'title' => __('Permintaan tamu').' · '.__('Kamar').' '.$first->room?->number,
                'body' => $items->map->label()->join(', ').($items->contains('priority', 'mendesak') ? ' · '.__('Mendesak') : ''),
                'data' => ['type' => 'permintaan', 'id' => (string) $first->id],
                'tag' => 'permintaan-'.$first->room_id,
            ]);
        }

        return $sent;
    }

    /** Perangkat yang login dan sudah memberi token notifikasi (token login belum melewati batas mutlak). */
    private function devices(): Collection
    {
        return PersonalAccessToken::whereNotNull('fcm_token')->where('tokenable_type', (new User)->getMorphClass())
            ->where('created_at', '>', now()->subMinutes((int) config('sanctum.expiration')))
            ->with('tokenable.employee')->get()
            ->filter(fn (PersonalAccessToken $token) => $token->tokenable instanceof User && $token->tokenable->canUseApp());
    }

    private function deliver(Collection $devices, callable $wants, array $message): int
    {
        $sent = 0;
        foreach ($devices as $device) {
            if (! $wants($device->tokenable)) {
                continue;
            }
            $result = $this->sender->send($device->fcm_token, $message);
            if ($result === AppPushSender::SENT) {
                $sent++;
            } elseif ($result === AppPushSender::GONE) {
                $device->forceFill(['fcm_token' => null])->save();
            }
        }

        return $sent;
    }
}
