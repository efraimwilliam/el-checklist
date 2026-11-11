<?php

namespace App\Http\Controllers;

use App\Models\Checklist;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\PushSubscription;
use App\Models\RoomIssue;
use App\Push\ChatPushNotifier;
use App\Models\User;
use App\Support\PhotoReencoder;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Obrolan pekerjaan ala WhatsApp (gate `chat`, dinyalakan Administrator di Pengaturan): chat pribadi, grup, dan obrolan per kendala / checklist.
 * Balas pesan, foto, hapus pesan, centang dibaca, "sedang mengetik". HR dan Administrator membaca semua percakapan.
 * Tanpa WebSocket: halaman memanggil `poll` tiap beberapa detik.
 */
class ChatController extends Controller
{
    private const MAX_BODY = 4000;

    private const PAGE = 300;

    private const TYPING_SECONDS = 6;

    /** Jumlah obrolan yang bisa disematkan satu pengguna. */
    public const MAX_PINS = 5;

    /** Dokumen yang boleh dikirim: ekstensi => jenis berkas yang dikirim balik (tidak memakai jenis dari browser). */
    public const DOCUMENT_TYPES = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'csv' => 'text/csv',
        'txt' => 'text/plain',
        'zip' => 'application/zip',
    ];

    public function index(Request $request): View
    {
        return view('chat.index', $this->sidebar($request));
    }

    /** Daftar obrolan (panel kiri) untuk dimuat ulang tanpa pindah halaman. */
    public function list(Request $request): JsonResponse
    {
        $data = $this->sidebar($request, $request->integer('aktif') ?: null);

        return response()->json([
            'html' => view('chat.partials.list', $data)->render(),
            'unread' => $request->user()->unreadChatCount(),
        ]);
    }

    /** Sematkan / lepas sematan obrolan (hanya peserta; maksimal MAX_PINS). Yang baru disematkan ada di urutan terakhir. */
    public function pin(Request $request, ChatThread $thread): JsonResponse
    {
        Gate::authorize('view', $thread);
        $me = $request->user();
        $participant = ChatParticipant::where(['chat_thread_id' => $thread->id, 'user_id' => $me->id])->first();
        abort_unless($participant, 403);

        if ($participant->pin_order === null) {
            $count = ChatParticipant::where('user_id', $me->id)->whereNotNull('pin_order')->count();
            if ($count >= self::MAX_PINS) {
                return response()->json(['message' => __('Maksimal :n obrolan yang bisa disematkan. Lepas satu dulu.', ['n' => self::MAX_PINS])], 422);
            }
            $participant->update(['pin_order' => $count + 1]);
        } else {
            $participant->update(['pin_order' => null]);
            $this->renumberPins($me);
        }

        return response()->json(['pinned' => $participant->fresh()->pin_order !== null]);
    }

    /**
     * Tandai belum dibaca (titik hijau di daftar) atau sudah dibaca (semua pesan dianggap terbaca), seperti geser baris di WhatsApp.
     * Hanya untuk peserta.
     */
    public function mark(Request $request, ChatThread $thread): JsonResponse
    {
        Gate::authorize('view', $thread);
        $data = $request->validate(['unread' => ['required', 'boolean']]);
        $participant = ChatParticipant::where(['chat_thread_id' => $thread->id, 'user_id' => $request->user()->id])->first();
        abort_unless($participant, 403);

        if ($data['unread']) {
            $participant->update(['marked_unread' => true]);
        } else {
            $participant->update(['marked_unread' => false, 'last_read_message_id' => max($participant->last_read_message_id, (int) $thread->messages()->max('id'))]);
        }

        return response()->json(['unread' => (bool) $data['unread']]);
    }

    /** Atur ulang urutan sematan: daftar id obrolan sesuai urutan baru (yang tidak disebut ikut di belakang). */
    public function reorderPins(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:'.self::MAX_PINS], 'ids.*' => ['integer', 'distinct']]);
        $me = $request->user();
        $pinned = ChatParticipant::where('user_id', $me->id)->whereNotNull('pin_order')->orderBy('pin_order')->pluck('pin_order', 'chat_thread_id');
        $order = collect($data['ids'])->filter(fn ($id) => $pinned->has($id))->merge($pinned->keys()->diff($data['ids']))->values();
        foreach ($order as $index => $threadId) {
            ChatParticipant::where(['chat_thread_id' => $threadId, 'user_id' => $me->id])->update(['pin_order' => $index + 1]);
        }

        return response()->json(['ok' => true]);
    }

    private function renumberPins(User $me): void
    {
        ChatParticipant::where('user_id', $me->id)->whereNotNull('pin_order')->orderBy('pin_order')->pluck('id')
            ->each(fn ($id, $index) => ChatParticipant::whereKey($id)->update(['pin_order' => $index + 1]));
    }

    /** Nyalakan notifikasi di perangkat ini: simpan langganan Web Push (satu endpoint = satu perangkat, milik pengguna yang login). */
    public function pushSubscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', 'url:https'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'in:aes128gcm,aesgcm'],
        ]);
        $me = $request->user();

        PushSubscription::updateOrCreate(['endpoint_hash' => PushSubscription::hashOf($data['endpoint'])], [
            'user_id' => $me->id, // perangkat yang sama dinyalakan pengguna lain = berpindah ke pengguna itu
            'endpoint' => $data['endpoint'],
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);

        // Perangkat terlama dibuang bila melewati batas per pengguna.
        $extra = PushSubscription::where('user_id', $me->id)->orderByDesc('updated_at')->orderByDesc('id')->pluck('id')->slice((int) config('webpush.max_per_user'));
        if ($extra->isNotEmpty()) {
            PushSubscription::whereIn('id', $extra)->delete();
        }

        return response()->json(['subscribed' => true]);
    }

    /** Apakah perangkat (endpoint) ini terdaftar untuk pengguna yang login? Dipakai tombol bel supaya perangkat bekas pengguna lain tidak tampil menyala. */
    public function pushStatus(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);

        return response()->json(['subscribed' => PushSubscription::where(['user_id' => $request->user()->id, 'endpoint_hash' => PushSubscription::hashOf($data['endpoint'])])->exists()]);
    }

    /** Matikan notifikasi di perangkat ini (juga dipanggil saat Keluar). */
    public function pushUnsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);
        PushSubscription::where(['user_id' => $request->user()->id, 'endpoint_hash' => PushSubscription::hashOf($data['endpoint'])])->delete();

        return response()->json(['subscribed' => false]);
    }

    /** Angka pesan belum dibaca untuk tombol obrolan melayang (ringan, dipanggil berkala). */
    public function unread(Request $request): JsonResponse
    {
        return response()->json(['unread' => $request->user()->unreadChatCount()]);
    }

    /** Chat pribadi dengan satu orang: dibuat saat pertama dibuka, setelahnya dibuka yang sama. */
    public function direct(Request $request, User $person): RedirectResponse
    {
        $me = $request->user();
        abort_if($person->id === $me->id || ! $person->canUseApp(), 404);

        $key = ChatThread::directKey($me->id, $person->id);
        try {
            $thread = ChatThread::firstOrCreate(['direct_key' => $key], ['created_by' => $me->id]);
        } catch (QueryException) {
            $thread = ChatThread::where('direct_key', $key)->firstOrFail(); // dua klik bersamaan
        }
        foreach ([$me->id, $person->id] as $id) {
            ChatParticipant::firstOrCreate(['chat_thread_id' => $thread->id, 'user_id' => $id]);
        }

        return redirect()->route('chat.show', $thread);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:120'],
            'users' => ['required', 'array', 'min:1', 'max:100'],
            'users.*' => ['integer', 'distinct'],
        ], [
            'subject.required' => __('Nama grup wajib diisi.'),
            'subject.max' => __('Nama grup maksimal 120 karakter.'),
            'users.required' => __('Pilih minimal satu anggota.'),
        ]);

        $me = $request->user();
        $ids = $this->activeUserIds($data['users'], $me);
        if ($ids === []) {
            throw ValidationException::withMessages(['users' => __('Pilih minimal satu anggota.')]);
        }

        $thread = DB::transaction(function () use ($me, $data, $ids) {
            $thread = ChatThread::create(['subject' => trim($data['subject']), 'created_by' => $me->id]);
            foreach ([$me->id, ...$ids] as $id) {
                ChatParticipant::create(['chat_thread_id' => $thread->id, 'user_id' => $id]);
            }
            $this->post($thread, $me, __(':name membuat grup ":group"', ['name' => $me->name(), 'group' => $thread->subject]), type: 'system');

            return $thread;
        });

        return redirect()->route('chat.show', $thread);
    }

    /**
     * Kirim kendala ke satu atau beberapa obrolan (seperti "teruskan" di WhatsApp): obrolan yang sudah ada dan / atau chat pribadi
     * dengan kontak. Pesannya berupa kartu kendala + catatan opsional. Hanya yang boleh melihat kendala itu yang bisa mengirimnya.
     */
    public function shareIssue(Request $request, RoomIssue $issue): RedirectResponse
    {
        Gate::authorize('view', $issue);
        $targets = $this->shareTo($request, ['room_issue_id' => $issue->id]);

        return $targets->count() === 1
            ? redirect()->route('chat.show', $targets->first())
            : redirect()->route('kamar.issues.show', $issue)->with('status', trans_choice('Kendala dikirim ke :n obrolan.|Kendala dikirim ke :n obrolan.', $targets->count(), ['n' => $targets->count()]));
    }

    /** Kirim checklist ke obrolan, mis. untuk mengingatkan pemiliknya menyelesaikan tugas. Butuh hak melihat checklist itu. */
    public function shareChecklist(Request $request, Checklist $checklist): RedirectResponse
    {
        Gate::authorize('view', $checklist);
        $targets = $this->shareTo($request, ['checklist_id' => $checklist->id]);

        return $targets->count() === 1
            ? redirect()->route('chat.show', $targets->first())
            : redirect()->route('checklists.show', $checklist)->with('status', trans_choice('Checklist dikirim ke :n obrolan.|Checklist dikirim ke :n obrolan.', $targets->count(), ['n' => $targets->count()]));
    }

    /** Kirim Chronology Report ke obrolan. Butuh hak melihat laporan itu. */
    public function shareChronology(Request $request, \App\Models\ChronologyReport $report): RedirectResponse
    {
        Gate::authorize('view', $report);
        $targets = $this->shareTo($request, ['chronology_report_id' => $report->id]);

        return $targets->count() === 1
            ? redirect()->route('chat.show', $targets->first())
            : redirect()->route('chronology.show', $report)->with('status', trans_choice('Laporan dikirim ke :n obrolan.|Laporan dikirim ke :n obrolan.', $targets->count(), ['n' => $targets->count()]));
    }

    /**
     * Tujuan kiriman: obrolan yang boleh dikirimi dan / atau kontak (chat pribadi dibuat bila belum ada), maks 10. Tiap tujuan mendapat
     * satu pesan berisi kartu ($link) + catatan opsional.
     *
     * @param  array<string, int>  $link
     */
    private function shareTo(Request $request, array $link): Collection
    {
        $me = $request->user();
        $data = $request->validate([
            'threads' => ['nullable', 'array', 'max:10'],
            'threads.*' => ['integer', 'distinct'],
            'people' => ['nullable', 'array', 'max:10'],
            'people.*' => ['integer', 'distinct'],
            'body' => ['nullable', 'string', 'max:'.self::MAX_BODY],
        ], ['threads.max' => __('Pilih paling banyak 10 obrolan.'), 'people.max' => __('Pilih paling banyak 10 orang.')]);

        $targets = ChatThread::with(['participants', 'issue', 'checklist'])->whereIn('id', $data['threads'] ?? [])->get()
            ->filter(fn (ChatThread $t) => Gate::allows('post', $t));
        $people = $this->activeUserIds($data['people'] ?? [], $me);
        if ($targets->isEmpty() && $people === []) {
            throw ValidationException::withMessages(['share' => __('Pilih minimal satu obrolan atau orang.')]);
        }
        if ($targets->count() + count($people) > 10) {
            throw ValidationException::withMessages(['share' => __('Pilih paling banyak 10 tujuan.')]);
        }
        foreach ($people as $id) {
            $key = ChatThread::directKey($me->id, $id);
            $thread = ChatThread::firstOrCreate(['direct_key' => $key], ['created_by' => $me->id]);
            foreach ([$me->id, $id] as $participant) {
                ChatParticipant::firstOrCreate(['chat_thread_id' => $thread->id, 'user_id' => $participant]);
            }
            $targets->push($thread->load('participants'));
        }
        $targets = $targets->unique('id')->values();

        $body = trim((string) ($data['body'] ?? ''));
        foreach ($targets as $thread) {
            $message = $this->post($thread, $me, $body !== '' ? $body : null);
            $message->update($link + ['mentions' => $this->mentionedIds($thread, $me, $body) ?: null]);
        }

        return $targets;
    }

    public function forIssue(Request $request, RoomIssue $issue): RedirectResponse
    {
        Gate::authorize('view', $issue);

        return $this->openLinked($request->user(), ['room_issue_id' => $issue->id]);
    }

    public function forChecklist(Request $request, Checklist $checklist): RedirectResponse
    {
        Gate::authorize('view', $checklist);

        return $this->openLinked($request->user(), ['checklist_id' => $checklist->id]);
    }

    public function show(Request $request, ChatThread $thread): View
    {
        Gate::authorize('view', $thread);
        $me = $request->user();
        $thread->load(['issue.room', 'checklist.employee', 'participants.user.employee.department']);

        $participant = $thread->participants->firstWhere('user_id', $me->id);
        $messages = $thread->messages()->with(['user.employee', 'replyTo.user.employee', 'sharedIssue.room', 'sharedIssue.category', 'sharedChecklist.items', 'sharedChecklist.employee', 'sharedReport.guests', 'sharedReport.entries', 'sharedReport.author.employee'])->latest('id')->limit(self::PAGE)->get()->reverse()->values();
        // Dari hasil cari / media: buka di sekitar pesan itu walau sudah lama.
        $target = $request->integer('pesan') ?: null;
        if ($target && ! $messages->contains('id', $target) && $thread->messages()->whereKey($target)->exists()) {
            $with = ['user.employee', 'replyTo.user.employee', 'sharedIssue.room', 'sharedIssue.category', 'sharedChecklist.items', 'sharedChecklist.employee', 'sharedReport.guests', 'sharedReport.entries', 'sharedReport.author.employee'];
            $messages = $thread->messages()->with($with)->where('id', '<', $target)->latest('id')->limit(100)->get()->reverse()->values()
                ->concat($thread->messages()->with($with)->where('id', '>=', $target)->orderBy('id')->limit(self::PAGE)->get())->values();
        }

        // Penanda "N pesan belum dibaca" seperti WhatsApp: hanya untuk peserta, dihitung sebelum ditandai terbaca.
        $unread = $participant
            ? $messages->filter(fn (ChatMessage $m) => $m->id > $participant->last_read_message_id && $m->user_id !== $me->id && ! $m->isSystem() && ! $m->isDeleted())
            : collect();
        $this->markRead($thread, $me, (int) $messages->last()?->id);
        ChatParticipant::where(['chat_thread_id' => $thread->id, 'user_id' => $me->id, 'marked_unread' => true])->update(['marked_unread' => false]);
        ChatPushNotifier::markPresent($thread->id, $me->id);

        $canInvite = Gate::allows('invite', $thread);

        return view('chat.show', $this->sidebar($request, $thread->id) + [
            'thread' => $thread,
            'messages' => $messages,
            'readUpto' => $this->readUpto($thread, $me),
            'firstUnread' => $unread->first()?->id,
            'unreadCount' => $unread->count(),
            'canPost' => Gate::allows('post', $thread),
            'canInvite' => $canInvite,
            'canLeave' => Gate::allows('leave', $thread),
            'candidates' => $canInvite ? $this->people($me)->reject(fn (User $u) => $thread->participants->contains('user_id', $u->id))->values() : collect(),
            'serverNow' => now()->toIso8601String(),
            'target' => $target,
        ]);
    }

    /** Polling: pesan baru, pesan yang baru dihapus, batas centang biru, dan siapa yang sedang mengetik. */
    public function poll(Request $request, ChatThread $thread): JsonResponse
    {
        Gate::authorize('view', $thread);
        $me = $request->user();
        $thread->load('participants.user.employee');
        $after = max(0, $request->integer('after'));
        $since = rescue(fn () => CarbonImmutable::parse((string) $request->query('since')), now()->subMinutes(2), false);

        $new = $thread->messages()->with(['user.employee', 'replyTo.user.employee', 'sharedIssue.room', 'sharedIssue.category', 'sharedChecklist.items', 'sharedChecklist.employee', 'sharedReport.guests', 'sharedReport.entries', 'sharedReport.author.employee'])->where('id', '>', $after)->orderBy('id')->limit(self::PAGE)->get();
        $changed = $thread->messages()->with(['user.employee', 'replyTo.user.employee', 'sharedIssue.room', 'sharedIssue.category', 'sharedChecklist.items', 'sharedChecklist.employee', 'sharedReport.guests', 'sharedReport.entries', 'sharedReport.author.employee'])->where('id', '<=', $after)
            ->whereNotNull('deleted_at')->where('deleted_at', '>=', $since)->get();
        $this->markRead($thread, $me, (int) $new->last()?->id);
        ChatPushNotifier::markPresent($thread->id, $me->id);

        return response()->json($this->payload($thread, $me, $new, $changed) + ['typing' => $this->typingNames($thread, $me)]);
    }

    public function send(Request $request, ChatThread $thread): JsonResponse|RedirectResponse
    {
        Gate::authorize('post', $thread);
        $me = $request->user();
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:'.self::MAX_BODY],
            'photo' => ['nullable', 'file', 'image', 'max:8192'],
            'file' => ['nullable', 'file', 'max:8192', 'mimes:'.implode(',', array_keys(self::DOCUMENT_TYPES))],
            'reply_to' => ['nullable', 'integer'],
        ], [
            'file.mimes' => __('Jenis dokumen tidak didukung. Kirim PDF, Word, Excel, PowerPoint, CSV, TXT, atau ZIP.'),
            'file.max' => __('Dokumen terlalu besar (maksimal 8 MB).'),
            'body.max' => __('Pesan terlalu panjang (maksimal :max karakter).', ['max' => self::MAX_BODY]),
            'photo.image' => __('File bukan foto yang valid.'),
            'photo.max' => __('Foto terlalu besar (maksimal 8 MB).'),
        ]);

        $body = trim((string) ($data['body'] ?? ''));
        if ($body === '' && ! $request->hasFile('photo') && ! $request->hasFile('file')) {
            throw ValidationException::withMessages(['body' => __('Tulis pesan, pilih foto, atau pilih dokumen.')]);
        }
        $replyTo = isset($data['reply_to']) && $thread->messages()->whereKey($data['reply_to'])->where('type', 'text')->exists() ? (int) $data['reply_to'] : null;

        $path = null;
        if ($request->hasFile('photo')) {
            $jpeg = PhotoReencoder::jpeg($request->file('photo'));
            $path = 'chat-photos/'.$thread->id.'/'.Str::uuid().'.jpg';
            Storage::disk('local')->put($path, $jpeg);
        }

        $message = $this->post($thread, $me, $body !== '' ? $body : null, $path, $replyTo);
        if (! $path && $request->hasFile('file')) {
            $message->update($this->storeDocument($thread, $request->file('file')));
        }
        if ($mentions = $this->mentionedIds($thread, $me, $body)) {
            $message->update(['mentions' => $mentions]);
        }
        $this->stopTyping($thread, $me);

        if (! $request->expectsJson()) {
            return redirect()->route('chat.show', $thread);
        }
        $thread->load('participants.user.employee');

        return response()->json($this->payload($thread, $me, collect([$message->load(['user.employee', 'replyTo.user.employee', 'sharedIssue.room', 'sharedIssue.category', 'sharedChecklist.items', 'sharedChecklist.employee', 'sharedReport.guests', 'sharedReport.entries', 'sharedReport.author.employee'])]), collect()));
    }

    /** Hapus untuk semua orang (pengirimnya saja), seperti WhatsApp: gelembung tetap ada dengan tulisan "Pesan ini dihapus". */
    public function destroy(Request $request, ChatThread $thread, ChatMessage $message): JsonResponse
    {
        abort_unless($message->chat_thread_id === $thread->id, 404);
        Gate::authorize('view', $thread);
        abort_unless($message->user_id === $request->user()->id && ! $message->isSystem(), 403);

        if (! $message->isDeleted()) {
            Storage::disk('local')->delete(array_filter([$message->photo_path, $message->file_path]));
            $message->update(['body' => null, 'mentions' => null, 'photo_path' => null, 'file_path' => null, 'file_name' => null, 'file_size' => null, 'file_mime' => null, 'deleted_at' => now()]);
        }
        $thread->load('participants.user.employee');

        return response()->json($this->payload($thread, $request->user(), collect(), collect([$message->load(['user.employee', 'replyTo.user.employee', 'sharedIssue.room', 'sharedIssue.category', 'sharedChecklist.items', 'sharedChecklist.employee', 'sharedReport.guests', 'sharedReport.entries', 'sharedReport.author.employee'])])));
    }

    public function photo(ChatThread $thread, ChatMessage $message): StreamedResponse
    {
        abort_unless($message->chat_thread_id === $thread->id && $message->photo_path, 404);
        Gate::authorize('view', $thread);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($message->photo_path), 404);

        return $disk->response($message->photo_path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Unduh / buka dokumen. PDF dibuka di browser, lainnya diunduh. */
    public function file(ChatThread $thread, ChatMessage $message): StreamedResponse
    {
        abort_unless($message->chat_thread_id === $thread->id && $message->file_path, 404);
        Gate::authorize('view', $thread);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($message->file_path), 404);
        $inline = $message->file_mime === 'application/pdf';

        return $disk->response($message->file_path, $message->file_name, [
            'Content-Type' => $message->file_mime ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }

    /** Cari teks di satu percakapan (terbaru dulu). */
    public function search(Request $request, ChatThread $thread): JsonResponse
    {
        Gate::authorize('view', $thread);
        $term = trim((string) $request->query('q'));
        if (mb_strlen($term) < 2) {
            return response()->json(['results' => []]);
        }
        $like = '%'.addcslashes($term, '%_\\').'%';
        $results = $thread->messages()->with('user.employee')->where('type', 'text')->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('body', 'like', $like)->orWhere('file_name', 'like', $like))
            ->latest('id')->limit(50)->get();
        $me = $request->user();

        return response()->json(['results' => $results->map(function (ChatMessage $m) use ($term, $me) {
            $at = $m->created_at->copy()->timezone(config('app.timezone'));
            $text = $m->body ?: $m->file_name;
            $position = mb_stripos($text, $term);
            $start = max(0, $position - 40);
            $excerpt = ($start > 0 ? '…' : '').mb_substr($text, $start, 140);
            $html = preg_replace('/'.preg_quote(e($term), '/').'/iu', '<mark class="rounded bg-amber-200 px-0.5">$0</mark>', e($excerpt)) ?? e($excerpt);

            return [
                'id' => $m->id,
                'author' => $m->user_id === $me->id ? __('Anda') : $m->user?->name(),
                'time' => $at->isToday() ? $at->format('H:i') : $at->format('d/m/y'),
                'html' => $html,
            ];
        })->values()]);
    }

    /** Media, tautan, dan dokumen yang pernah dikirim di percakapan (untuk Info obrolan). */
    public function media(Request $request, ChatThread $thread): JsonResponse
    {
        Gate::authorize('view', $thread);
        $base = fn () => $thread->messages()->with('user.employee')->where('type', 'text')->whereNull('deleted_at')->latest('id');
        $links = $base()->where('body', 'like', '%http%')->limit(300)->get()->flatMap(function (ChatMessage $m) {
            preg_match_all(ChatMessage::URL_PATTERN, (string) $m->body, $found);

            return collect($found[0])->map(fn ($url) => ['url' => $url, 'message' => $m]);
        })->take(100);

        return response()->json(['html' => view('chat.partials.media', [
            'thread' => $thread,
            'photos' => $base()->whereNotNull('photo_path')->limit(120)->get(),
            'links' => $links,
            'documents' => $base()->whereNotNull('file_path')->limit(100)->get(),
        ])->render()]);
    }

    /** Ganti / hapus foto grup (anggota grup). */
    public function groupPhoto(Request $request, ChatThread $thread): RedirectResponse
    {
        Gate::authorize('invite', $thread);
        $me = $request->user();
        $disk = Storage::disk('local');

        if ($request->boolean('hapus')) {
            if ($thread->photo_path) {
                $disk->delete($thread->photo_path);
                $thread->update(['photo_path' => null]);
                $this->post($thread, $me, __(':name menghapus foto grup', ['name' => $me->name()]), type: 'system');
            }

            return redirect()->route('chat.show', $thread);
        }

        $request->validate(['photo' => ['required', 'file', 'image', 'max:8192']], [
            'photo.required' => __('Pilih foto grup.'),
            'photo.image' => __('File bukan foto yang valid.'),
            'photo.max' => __('Foto terlalu besar (maksimal 8 MB).'),
        ]);
        $path = 'chat-group-photos/'.$thread->id.'-'.Str::uuid().'.jpg';
        $disk->put($path, PhotoReencoder::jpeg($request->file('photo')));
        if ($thread->photo_path) {
            $disk->delete($thread->photo_path);
        }
        $thread->update(['photo_path' => $path]);
        $this->post($thread, $me, __(':name mengganti foto grup', ['name' => $me->name()]), type: 'system');

        return redirect()->route('chat.show', $thread);
    }

    public function groupPhotoShow(ChatThread $thread): StreamedResponse
    {
        Gate::authorize('view', $thread);
        $disk = Storage::disk('local');
        abort_unless($thread->photo_path && $disk->exists($thread->photo_path), 404);

        return $disk->response($thread->photo_path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Sedang mengetik: disimpan beberapa detik di cache, dibaca lewat polling. */
    public function typing(Request $request, ChatThread $thread): JsonResponse
    {
        Gate::authorize('post', $thread);
        $key = $this->typingKey($thread);
        $map = array_filter((array) Cache::get($key, []), fn ($at) => $at >= now()->timestamp - self::TYPING_SECONDS);
        $map[$request->user()->id] = now()->timestamp;
        Cache::put($key, $map, self::TYPING_SECONDS * 2);

        return response()->json(['ok' => true]);
    }

    public function invite(Request $request, ChatThread $thread): RedirectResponse
    {
        Gate::authorize('invite', $thread);
        $data = $request->validate(['users' => ['required', 'array', 'min:1', 'max:100'], 'users.*' => ['integer', 'distinct']], ['users.required' => __('Pilih minimal satu orang.')]);

        $added = collect();
        foreach ($this->activeUserIds($data['users'], $request->user()) as $id) {
            if (ChatParticipant::firstOrCreate(['chat_thread_id' => $thread->id, 'user_id' => $id])->wasRecentlyCreated) {
                $added->push($id);
            }
        }
        if ($added->isNotEmpty()) {
            $names = User::with('employee')->whereIn('id', $added)->get()->map->name()->implode(', ');
            $this->post($thread, $request->user(), __(':name menambahkan :people', ['name' => $request->user()->name(), 'people' => $names]), type: 'system');
        }

        return redirect()->route('chat.show', $thread);
    }

    public function leave(Request $request, ChatThread $thread): RedirectResponse
    {
        Gate::authorize('leave', $thread);
        $me = $request->user();
        $this->post($thread, $me, __(':name keluar', ['name' => $me->name()]), type: 'system');
        ChatParticipant::where(['chat_thread_id' => $thread->id, 'user_id' => $me->id])->delete();

        return redirect()->route('chat.index')->with('status', __('Anda keluar dari grup.'));
    }

    // ---------------------------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function sidebar(Request $request, ?int $activeId = null): array
    {
        $me = $request->user();
        $canSeeAll = $me->role->overridesReview();
        $viewingAll = $canSeeAll && $request->boolean('semua');

        $pinOrder = ChatParticipant::select('pin_order')->whereColumn('chat_participants.chat_thread_id', 'chat_threads.id')->where('chat_participants.user_id', $me->id)->limit(1);
        $threads = ChatThread::query()
            ->select('chat_threads.*')->selectSub($pinOrder, 'pin_order')
            ->selectSub(ChatParticipant::select('marked_unread')->whereColumn('chat_participants.chat_thread_id', 'chat_threads.id')->where('chat_participants.user_id', $me->id)->limit(1), 'marked_unread')
            ->with(['latestMessage.user.employee', 'participants.user.employee.department', 'issue.room', 'checklist.employee'])
            ->withCount(['messages as unread_count' => fn ($q) => $q->where('chat_messages.user_id', '!=', $me->id)->whereNull('chat_messages.deleted_at')
                ->whereRaw('chat_messages.id > coalesce((select p.last_read_message_id from chat_participants p where p.chat_thread_id = chat_threads.id and p.user_id = ?), 0)', [$me->id])])
            ->withCount(['messages as mention_count' => fn ($q) => $q->whereNull('chat_messages.deleted_at')->whereJsonContains('chat_messages.mentions', $me->id)
                ->whereRaw('chat_messages.id > coalesce((select p.last_read_message_id from chat_participants p where p.chat_thread_id = chat_threads.id and p.user_id = ?), 0)', [$me->id])])
            ->when(! $viewingAll, fn ($q) => $q->whereHas('participants', fn ($p) => $p->where('user_id', $me->id)))
            // Chat pribadi / obrolan kendala yang belum berisi pesan tidak ditampilkan, kecuali yang sedang dibuka.
            ->where(fn ($q) => $q->whereNotNull('last_message_at')->when($activeId, fn ($w) => $w->orWhere('id', $activeId))
                ->orWhereHas('participants', fn ($p) => $p->where('user_id', $me->id)->whereNotNull('pin_order')))
            // Yang disematkan di atas menurut urutan sematan, sisanya menurut pesan terakhir. Tab "Semua percakapan" tanpa sematan.
            ->when(! $viewingAll, fn ($q) => $q->orderByRaw('pin_order is null')->orderBy('pin_order'))
            ->orderByDesc('last_message_at')->orderByDesc('id')->limit(200)->get();

        return [
            'threads' => $threads,
            'people' => $this->people($me),
            'activeId' => $activeId,
            'canSeeAll' => $canSeeAll,
            'viewingAll' => $viewingAll,
            'maxPins' => self::MAX_PINS,
        ];
    }

    /** Semua pengguna aktif selain diri sendiri, urut nama (kontak). */
    private function people(User $me): Collection
    {
        return User::with('employee.department')->where('is_active', true)->whereKeyNot($me->id)
            ->whereHas('employee', fn ($q) => $q->where('is_active', true))
            ->get()->sortBy(fn (User $u) => mb_strtolower($u->name()))->values();
    }

    /** @param array<string, int> $link */
    private function openLinked(User $user, array $link): RedirectResponse
    {
        $thread = ChatThread::firstOrCreate($link, ['created_by' => $user->id]);
        ChatParticipant::firstOrCreate(['chat_thread_id' => $thread->id, 'user_id' => $user->id]);

        return redirect()->route('chat.show', $thread);
    }

    private function post(ChatThread $thread, User $user, ?string $body, ?string $photo = null, ?int $replyTo = null, string $type = 'text'): ChatMessage
    {
        $message = $thread->messages()->create(['user_id' => $user->id, 'type' => $type, 'body' => $body, 'photo_path' => $photo, 'reply_to_id' => $replyTo]);
        $thread->forceFill(['last_message_at' => $message->created_at])->save();
        // Pengirim otomatis jadi peserta (kendala / checklist) dan pesannya sendiri langsung dianggap terbaca. Yang keluar grup tidak ditambahkan lagi.
        if ($type === 'text' || $thread->isParticipant($user)) {
            ChatParticipant::updateOrCreate(['chat_thread_id' => $thread->id, 'user_id' => $user->id], ['last_read_message_id' => $message->id]);
        }
        // Notifikasi ke perangkat peserta lain: dikirim SESUDAH jawaban ke pengirim (tidak memperlambat kirim pesan); mention
        // dan lampiran yang dipasang sesudah ini ikut terbaca karena pesan dimuat ulang dari database.
        if ($type === 'text') {
            $threadId = $thread->id;
            $messageId = $message->id;
            defer(fn () => app(ChatPushNotifier::class)->notify($threadId, $messageId));
        }

        return $message;
    }

    private function markRead(ChatThread $thread, User $user, int $lastId): void
    {
        if ($lastId > 0) {
            ChatParticipant::where(['chat_thread_id' => $thread->id, 'user_id' => $user->id])->where('last_read_message_id', '<', $lastId)->update(['last_read_message_id' => $lastId]);
        }
    }

    /** Pesan sampai id ini sudah dibaca SEMUA peserta lain (centang biru). */
    private function readUpto(ChatThread $thread, User $me): int
    {
        return (int) ChatParticipant::where('chat_thread_id', $thread->id)->where('user_id', '!=', $me->id)->min('last_read_message_id');
    }

    /** @return array<string, mixed> */
    private function payload(ChatThread $thread, User $me, Collection $new, Collection $changed): array
    {
        $readUpto = $this->readUpto($thread, $me);
        $render = fn (ChatMessage $m) => [
            'id' => $m->id,
            'day' => $m->created_at->copy()->timezone(config('app.timezone'))->toDateString(),
            'html' => view('chat.partials.message', ['message' => $m, 'me' => $me, 'thread' => $thread, 'readUpto' => $readUpto])->render(),
        ];

        return [
            'messages' => $new->map($render)->values()->all(),
            'changed' => $changed->map($render)->values()->all(),
            'read_upto' => $readUpto,
            'now' => now()->toIso8601String(),
        ];
    }

    private function typingKey(ChatThread $thread): string
    {
        return 'chat.typing.'.$thread->id;
    }

    /** @return list<string> */
    private function typingNames(ChatThread $thread, User $me): array
    {
        $ids = collect((array) Cache::get($this->typingKey($thread), []))
            ->filter(fn ($at, $id) => (int) $id !== $me->id && $at >= now()->timestamp - self::TYPING_SECONDS)->keys();

        return $thread->participants->whereIn('user_id', $ids->map(fn ($id) => (int) $id))->map(fn ($p) => $p->user?->name())->filter()->values()->all();
    }

    private function stopTyping(ChatThread $thread, User $me): void
    {
        $key = $this->typingKey($thread);
        $map = (array) Cache::get($key, []);
        unset($map[$me->id]);
        Cache::put($key, $map, self::TYPING_SECONDS * 2);
    }

    /** @return array{file_path: string, file_name: string, file_size: int, file_mime: string} */
    private function storeDocument(ChatThread $thread, \Illuminate\Http\UploadedFile $file): array
    {
        $extension = mb_strtolower($file->getClientOriginalExtension());
        if (! isset(self::DOCUMENT_TYPES[$extension])) {
            $extension = (string) $file->guessExtension();
        }
        abort_unless(isset(self::DOCUMENT_TYPES[$extension]), 422);
        // Nama asli hanya untuk ditampilkan / nama unduhan; berkas disimpan dengan nama acak.
        $name = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', $file->getClientOriginalName()) ?? '');
        $name = Str::limit($name !== '' ? $name : 'dokumen.'.$extension, 180, '');
        $path = $file->storeAs('chat-files/'.$thread->id, Str::uuid().'.'.$extension, 'local');

        return ['file_path' => $path, 'file_name' => $name, 'file_size' => (int) $file->getSize(), 'file_mime' => self::DOCUMENT_TYPES[$extension]];
    }

    /**
     * "@Nama Lengkap" di teks = peserta yang disebut (tanpa membedakan huruf besar / kecil; nama terpanjang dicocokkan dulu
     * supaya "@Made Demo Putra" tidak terbaca sebagai "@Made Demo").
     *
     * @return list<int>
     */
    private function mentionedIds(ChatThread $thread, User $me, string $body): array
    {
        if (! str_contains($body, '@')) {
            return [];
        }
        $text = mb_strtolower($body);
        $ids = [];
        $thread->loadMissing('participants.user.employee');
        foreach ($thread->participants->pluck('user')->filter()->reject(fn (User $u) => $u->id === $me->id)->sortByDesc(fn (User $u) => mb_strlen($u->name())) as $person) {
            $needle = '@'.mb_strtolower($person->name());
            if (str_contains($text, $needle)) {
                $ids[] = $person->id;
                $text = str_replace($needle, ' ', $text);
            }
        }

        return $ids;
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return list<int>
     */
    private function activeUserIds(array $ids, User $self): array
    {
        return User::whereIn('id', $ids)->whereKeyNot($self->id)->where('is_active', true)
            ->whereHas('employee', fn ($q) => $q->where('is_active', true))->pluck('id')->all();
    }
}
