<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\User;
use App\Push\ChatPushNotifier;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * Obrolan untuk aplikasi Android: daftar obrolan milik sendiri, pesan dalam bentuk data (bukan HTML seperti web), kontak, dan
 * membuka chat pribadi. Mengirim teks / foto, foto pesan, dan hapus pesan memakai `App\Http\Controllers\ChatController` yang sama.
 * Izin: gate `chat` + `ChatThreadPolicy`. Polling seperti web (aplikasi memanggil `pesan?after=` tiap beberapa detik saat dibuka).
 */
class ChatController extends Controller
{
    private const PAGE = 50;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('chat');
        $me = $request->user();

        $threads = ChatThread::whereHas('participants', fn ($q) => $q->where('user_id', $me->id))
            ->whereNotNull('last_message_at')
            ->with(['latestMessage.user.employee', 'latestMessage.sharedIssue.room', 'latestMessage.sharedRequest.room', 'participants.user.employee.department', 'issue.room', 'checklist.employee'])
            ->withCount(['messages as unread_count' => fn ($q) => $q->where('chat_messages.user_id', '!=', $me->id)->whereNull('chat_messages.deleted_at')
                ->whereRaw('chat_messages.id > coalesce((select p.last_read_message_id from chat_participants p where p.chat_thread_id = chat_threads.id and p.user_id = ?), 0)', [$me->id])])
            ->orderByDesc('last_message_at')->limit(100)->get();

        return response()->json([
            'threads' => $threads->map(function (ChatThread $t) use ($me) {
                $last = $t->latestMessage;
                $mine = $last && $last->user_id === $me->id && ! $last->isSystem();
                // Centang biru seperti WhatsApp: pesan terakhir saya sudah dibaca semua peserta lain.
                $readUpto = (int) $t->participants->where('user_id', '!=', $me->id)->min('last_read_message_id');

                return [
                    'id' => $t->id,
                    'title' => $t->title($me),
                    'initials' => $t->initials($me),
                    'group' => ! $t->isDirect(),
                    'photo' => $t->isDirect() ? $this->photo($t->others($me)->first()) : null,
                    'last' => $last ? (! $mine && ! $t->isDirect() && ! $last->isSystem() ? $last->user?->name().': ' : '').$last->snippet(60) : '',
                    'last_mine' => $mine,
                    'last_read' => $mine && $last->id <= $readUpto,
                    'last_at' => $t->last_message_at?->toIso8601String(),
                    'unread' => (int) $t->unread_count,
                ];
            })->values(),
            'unread' => $me->unreadChatCount(),
        ]);
    }

    /** Pesan: `after=0` = 50 terakhir; selain itu yang lebih baru dari id itu. Sekaligus menandai terbaca (centang biru di web). */
    public function messages(Request $request, ChatThread $thread): JsonResponse
    {
        Gate::authorize('view', $thread);
        $me = $request->user();
        $after = max(0, $request->integer('after'));
        $with = ['user.employee', 'replyTo.user.employee', 'sharedIssue.room', 'sharedIssue.category', 'sharedRequest.room', 'sharedChecklist.employee', 'sharedReport.guests', 'sharedLost.room'];

        $messages = $after === 0
            ? $thread->messages()->with($with)->latest('id')->limit(self::PAGE)->get()->reverse()->values()
            : $thread->messages()->with($with)->where('id', '>', $after)->orderBy('id')->limit(self::PAGE)->get();
        // Pesan lama yang dihapus pengirimnya sesudah dimuat: id-nya dikirim supaya aplikasi menggantinya.
        $deleted = $after > 0 ? $thread->messages()->where('id', '<=', $after)->whereNotNull('deleted_at')->where('deleted_at', '>=', now()->subMinutes(10))->pluck('id') : collect();

        if ($last = $messages->last()?->id) {
            ChatParticipant::where(['chat_thread_id' => $thread->id, 'user_id' => $me->id])->where('last_read_message_id', '<', $last)->update(['last_read_message_id' => $last]);
        }
        ChatParticipant::where(['chat_thread_id' => $thread->id, 'user_id' => $me->id, 'marked_unread' => true])->update(['marked_unread' => false]);
        ChatPushNotifier::markPresent($thread->id, $me->id); // sedang dibuka = tidak dikirimi notifikasi
        $thread->load('participants.user.employee.department', 'issue.room', 'checklist.employee');

        // "sedang mengetik…": diisi `ChatController::typing` (cache beberapa detik, sama dengan web).
        $typing = collect((array) Cache::get('chat.typing.'.$thread->id, []))
            ->filter(fn ($at, $id) => (int) $id !== $me->id && $at >= now()->timestamp - 6)->keys()->map(fn ($id) => (int) $id);

        return response()->json([
            'thread' => [
                'id' => $thread->id, 'title' => $thread->title($me), 'subtitle' => $thread->subtitle($me), 'initials' => $thread->initials($me),
                'group' => ! $thread->isDirect(), 'photo' => $thread->isDirect() ? $this->photo($thread->others($me)->first()) : null,
                'can_post' => Gate::allows('post', $thread),
            ],
            'typing' => $thread->participants->whereIn('user_id', $typing)->map(fn ($p) => $p->user?->name())->filter()->values(),
            'messages' => $messages->map(fn (ChatMessage $m) => $this->message($m, $me))->values(),
            'deleted' => $deleted->values(),
            'read_upto' => (int) ChatParticipant::where('chat_thread_id', $thread->id)->where('user_id', '!=', $me->id)->min('last_read_message_id'),
        ]);
    }

    /** Kontak untuk chat pribadi: pengguna aktif selain diri sendiri. */
    public function contacts(Request $request): JsonResponse
    {
        Gate::authorize('chat');
        $me = $request->user();

        return response()->json(['people' => User::with('employee.department')->where('is_active', true)->whereKeyNot($me->id)
            ->whereHas('employee', fn ($q) => $q->where('is_active', true))->get()
            ->filter->canUseApp()
            ->sortBy(fn (User $u) => mb_strtolower($u->name()))->values()
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name(), 'photo' => $this->photo($u), 'detail' => collect([$u->employee?->position_title, $u->employee?->department?->name])->filter()->implode(' · ')])]);
    }

    /** Chat pribadi: dibuat saat pertama dibuka, setelahnya yang sama (sama dengan web `direct`). */
    public function direct(Request $request, User $person): JsonResponse
    {
        Gate::authorize('chat');
        $me = $request->user();
        abort_if($person->id === $me->id || ! $person->canUseApp(), 404);
        $key = ChatThread::directKey($me->id, $person->id);
        try {
            $thread = ChatThread::firstOrCreate(['direct_key' => $key], ['created_by' => $me->id]);
        } catch (QueryException) {
            $thread = ChatThread::where('direct_key', $key)->firstOrFail();
        }
        foreach ([$me->id, $person->id] as $id) {
            ChatParticipant::firstOrCreate(['chat_thread_id' => $thread->id, 'user_id' => $id]);
        }

        return response()->json(['id' => $thread->id]);
    }

    /** Alamat foto profil (salinan dari Absensi) untuk avatar; null = pakai inisial. */
    private function photo(?User $user): ?string
    {
        $employee = $user?->employee;

        return $employee?->photo_cached_version ? "/obrolan/pegawai/{$employee->id}/foto?v={$employee->photo_cached_version}" : null;
    }

    /** @return array<string, mixed> */
    private function message(ChatMessage $m, User $me): array
    {
        $deleted = $m->isDeleted();
        // Kartu (kendala / permintaan / checklist / laporan / barang): ringkasan teks + tautan ke layar aplikasi bila ada.
        $card = match (true) {
            $deleted => null,
            (bool) $m->room_issue_id => ['type' => 'kendala', 'id' => $m->room_issue_id, 'can_open' => $m->sharedIssue && Gate::forUser($me)->allows('view', $m->sharedIssue), 'text' => '🛠 '.__('Kendala').' · '.($m->sharedIssue ? $m->sharedIssue->placeTitle().' · '.($m->sharedIssue->category?->name ?? '') : __('sudah dihapus'))],
            (bool) $m->guest_request_id => ['type' => 'permintaan', 'id' => $m->guest_request_id, 'can_open' => $m->sharedRequest && Gate::forUser($me)->allows('view', $m->sharedRequest), 'text' => '🛎 '.($m->sharedRequest ? __('Kamar').' '.$m->sharedRequest->room?->number.' · '.$m->sharedRequest->label() : __('Permintaan'))],
            (bool) $m->checklist_id => ['type' => 'checklist', 'id' => $m->checklist_id, 'text' => '📋 '.__('Checklist').' · '.($m->sharedChecklist?->employee?->name ?? '')],
            (bool) $m->chronology_report_id => ['type' => 'chronology', 'id' => $m->chronology_report_id, 'text' => '📝 Chronology Report · '.($m->sharedReport?->guests->first()?->name ?? '')],
            (bool) $m->lost_item_id => ['type' => 'lost', 'id' => $m->lost_item_id, 'text' => '🧳 '.__('Barang tertinggal').' · '.($m->sharedLost?->placeTitle() ?? '')],
            default => null,
        };

        return [
            'id' => $m->id,
            'mine' => $m->user_id === $me->id,
            'system' => $m->isSystem(),
            'user' => $m->user?->name(),
            'user_id' => $m->user_id,
            'body' => $deleted ? null : $m->body,
            'deleted' => $deleted,
            'photo' => ! $deleted && (bool) $m->photo_path,
            'file' => ! $deleted && $m->file_path ? $m->file_name : null,
            'card' => $card,
            'reply' => $m->replyTo ? ['user' => $m->replyTo->user?->name(), 'text' => $m->replyTo->snippet(80)] : null,
            'at' => $m->created_at?->toIso8601String(),
        ];
    }
}
