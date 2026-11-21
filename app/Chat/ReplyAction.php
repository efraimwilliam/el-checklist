<?php

namespace App\Chat;

use App\Models\ChatMessage;
use App\Models\GuestRequest;
use App\Models\RoomIssue;
use App\Models\RoomIssueUpdate;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Balasan singkat pada kartu Permintaan tamu / Kendala di obrolan, seperti kebiasaan grup WhatsApp. HANYA berlaku untuk pesan
 * yang membalas kartu itu (geser / Balas); kata-katanya di Kamus ketik bebas:
 *   - kelompok `claim` ("copy", "ok", "otw", "siap") = Saya ambil (policy `claim`);
 *   - kelompok `done` ("done", "sudah", "selesai") = Selesai: permintaan (policy `complete`), kendala Done (diambil dulu bila belum).
 * Hak dan cara menyimpan sama dengan tombol di kartu / halaman; pembaruan atomik supaya dua balasan bersamaan tidak sama-sama berhasil.
 */
class ReplyAction
{
    /**
     * Yang terkena bila pengguna membalas pesan ini: permintaan yang menempel pada pesan (ditambah jenis lain yang dicatat dari pesan
     * ketik bebas yang sama, mis. "1206 req 2 mw + 1 towel") dan / atau kendalanya, yang masih boleh di-$action-kan pengguna ini.
     *
     * @param  'claim'|'done'  $action
     * @return array{requests: Collection<int, GuestRequest>, issue: ?RoomIssue}
     */
    public function targets(User $user, ChatMessage $replied, string $action): array
    {
        $requests = collect();
        if ($request = $replied->guest_request_id ? GuestRequest::find($replied->guest_request_id) : null) {
            // Jenis lain dari pesan yang sama jadi kartu tersendiri; dikenali dari kamar, pencatat, isi pesan, dan waktu catat yang sama.
            $requests = $request->source === 'chat'
                ? GuestRequest::where(['room_id' => $request->room_id, 'requested_by' => $request->requested_by, 'note' => $request->note, 'source' => 'chat'])
                    ->whereBetween('created_at', [$request->created_at->copy()->subSeconds(5), $request->created_at->copy()->addSeconds(5)])->get()
                : collect([$request]);
        }
        $issue = $replied->room_issue_id ? RoomIssue::find($replied->room_issue_id) : null;
        $gate = Gate::forUser($user);

        return [
            // Pelapor tidak mengambil / menyelesaikan laporannya sendiri (berlaku untuk balasan obrolan dan bot Telegram).
            'requests' => $requests->filter(fn (GuestRequest $r) => $r->requested_by !== $user->id && $gate->allows($action === 'done' ? 'complete' : 'claim', $r))->values(),
            'issue' => $issue && $issue->reported_by !== $user->id && ($gate->allows('claim', $issue) || ($action === 'done' && $gate->allows('work', $issue))) ? $issue : null,
        ];
    }

    /** @param array{requests: Collection<int, GuestRequest>, issue: ?RoomIssue} $targets @return list<string> */
    public function labels(array $targets): array
    {
        return [
            ...$targets['requests']->map(fn (GuestRequest $r) => __('Kamar').' '.$r->room?->number.' · '.$r->label())->all(),
            ...($targets['issue'] ? [__('Kendala').' '.$targets['issue']->placeTitle().($targets['issue']->category ? ' · '.$targets['issue']->category->name : '')] : []),
        ];
    }

    /**
     * Jalankan atas nama pengguna. Yang ternyata sudah diambil / diselesaikan orang lain dilewati.
     *
     * @param  'claim'|'done'  $action
     * @return array{requests: Collection<int, GuestRequest>, issue: ?RoomIssue} yang berhasil
     */
    public function apply(User $user, ChatMessage $replied, string $action): array
    {
        $targets = $this->targets($user, $replied, $action);
        $won = ['requests' => collect(), 'issue' => null];
        $now = now();

        foreach ($targets['requests'] as $request) {
            $updated = $action === 'done'
                // Sama dengan tombol Selesai: boleh langsung dari Baru, pengambilnya pengguna ini bila belum ada.
                ? GuestRequest::whereKey($request->id)->where('status', '!=', GuestRequest::STATUS_DONE)->update([
                    'status' => GuestRequest::STATUS_DONE, 'completed_by' => $user->id, 'completed_at' => $now,
                    'claimed_by' => $request->claimed_by ?? $user->id, 'claimed_at' => $request->claimed_at ?? $now, 'updated_at' => $now,
                ])
                : GuestRequest::whereKey($request->id)->where('status', GuestRequest::STATUS_NEW)->whereNull('claimed_by')
                    ->update(['status' => GuestRequest::STATUS_CLAIMED, 'claimed_by' => $user->id, 'claimed_at' => $now, 'updated_at' => $now]);
            $updated && $won['requests']->push($request);
        }

        if ($issue = $targets['issue']) {
            $won['issue'] = DB::transaction(function () use ($issue, $user, $action, $now) {
                // Belum diambil: diambil dulu atas nama pengguna ini (sama dengan Saya ambil, tercatat di riwayat).
                $claimed = RoomIssue::whereKey($issue->id)->whereNull('claimed_by')->where('status', '!=', RoomIssue::STATUS_DONE)
                    ->update(['claimed_by' => $user->id, 'claimed_at' => $now, 'status' => RoomIssue::STATUS_WORKING]);
                if ($claimed) {
                    $this->log($issue, $user, __(':name mengambil kendala ini.', ['name' => $user->name()]), 'ambil');
                }
                if ($action === 'claim') {
                    return $claimed ? $issue : null;
                }
                // Done = sama dengan status Done di halaman kendala (hanya yang boleh mengerjakan, sebelum Done).
                $issue->refresh();
                if (! Gate::forUser($user)->allows('work', $issue)) {
                    return null;
                }
                $this->log($issue, $user, __('Selesai.'), 'selesai');
                $issue->update(['status' => RoomIssue::STATUS_DONE, 'completed_at' => $now, 'completed_by' => $user->id]);

                return $issue;
            });
        }

        // Kartu di Telegram (bila ada) ikut diperbarui, dari obrolan web maupun dari bot.
        $notifier = app(\App\Telegram\TelegramNotifier::class);
        $won['requests']->each(fn (GuestRequest $r) => $notifier->refresh($r));
        $won['issue'] && $notifier->refresh($won['issue']);

        return $won;
    }

    /** HOD / HR / Admin menugaskan: menjadikan $to pengambil (sama dengan Saya ambil atas namanya). Hanya bila belum diambil siapa pun. */
    public function assign(User $by, User $to, GuestRequest|RoomIssue $model): bool
    {
        $now = now();
        if ($model instanceof GuestRequest) {
            $done = GuestRequest::whereKey($model->id)->where('status', GuestRequest::STATUS_NEW)->whereNull('claimed_by')
                ->update(['status' => GuestRequest::STATUS_CLAIMED, 'claimed_by' => $to->id, 'claimed_at' => $now, 'updated_at' => $now]);
        } else {
            $done = DB::transaction(function () use ($model, $by, $to, $now) {
                $claimed = RoomIssue::whereKey($model->id)->whereNull('claimed_by')->where('status', '!=', RoomIssue::STATUS_DONE)
                    ->update(['claimed_by' => $to->id, 'claimed_at' => $now, 'status' => RoomIssue::STATUS_WORKING]);
                $claimed && $this->log($model, $by, __(':by menugaskan kendala ini kepada :to.', ['by' => $by->name(), 'to' => $to->name()]), 'ambil');

                return $claimed;
            });
        }
        $done && app(\App\Telegram\TelegramNotifier::class)->refresh($model);

        return (bool) $done;
    }

    private function log(RoomIssue $issue, User $user, string $body, string $kind): void
    {
        RoomIssueUpdate::create(['room_issue_id' => $issue->id, 'user_id' => $user->id, 'department_id' => $user->checklistDepartmentId(), 'body' => $body, 'kind' => $kind]);
    }
}
