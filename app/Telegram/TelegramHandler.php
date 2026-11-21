<?php

namespace App\Telegram;

use App\Chat\QuickEntry;
use App\Chat\QuickRecorder;
use App\Chat\ReplyAction;
use App\Checklists\ChecklistSettings;
use App\Models\ChatMessage;
use App\Http\Controllers\LostItemController;
use App\Models\GuestRequest;
use App\Models\LostItem;
use App\Models\RoomIssue;
use App\Models\RoomIssueUpdate;
use App\Rooms\RoomIssuePhotoService;
use App\Models\TelegramCard;
use App\Models\User;
use App\Support\PhotoReencoder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * Memproses satu update Telegram (webhook). Aturan sama dengan obrolan web: bahasa ketik bebas (QuickEntry), balasan "copy" / "done"
 * (ReplyAction), tombol kartu, dan hak akses (policy) atas nama pengguna yang menautkan akun Telegram-nya (users.telegram_id).
 * Pesan dari akun yang belum tertaut diabaikan di grup (tanpa membalas, supaya grup tidak ramai) dan diberi petunjuk di chat pribadi.
 */
class TelegramHandler
{
    public const LINK_CACHE = 'telegram.link.';

    public const PENDING_CACHE = 'telegram.issue.';

    public function __construct(
        private readonly TelegramApi $api,
        private readonly TelegramNotifier $notifier,
        private readonly QuickEntry $quick,
        private readonly QuickRecorder $recorder,
        private readonly ReplyAction $replies,
        private readonly ChronologyFlow $chronology,
        private readonly ChecklistFlow $checklist,
    ) {}

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->callback($update['callback_query']);
        } elseif (isset($update['message']['text']) || isset($update['message']['photo'])) {
            $this->message($update['message']);
        }
    }

    /** @param array<string, mixed> $m */
    private function message(array $m): void
    {
        $chat = (int) $m['chat']['id'];
        $text = trim((string) ($m['text'] ?? $m['caption'] ?? ''));
        if ($text === '' && ! isset($m['photo'])) {
            return;
        }
        $private = ($m['chat']['type'] ?? '') === 'private';
        $from = (int) ($m['from']['id'] ?? 0);

        if ($private && str_starts_with($text, '/start')) {
            $this->link($chat, $from, trim(substr($text, 6)));

            return;
        }
        $user = $this->user($from);
        if (! $user) {
            $private && $this->api->send($chat, e(__('Akun Telegram ini belum terhubung. Buka aplikasi > Profil > Hubungkan Telegram, lalu ketuk tautannya.')));

            return;
        }
        // Foto yang membalas kartu kendala = foto bukti pekerjaan (keterangan jadi catatan; "done" sekaligus menyelesaikan).
        if (isset($m['photo']) && ($replyId = $m['reply_to_message']['message_id'] ?? null)
            && ($card = TelegramCard::where(['chat_id' => $chat, 'message_id' => $replyId])->first())?->room_issue_id) {
            $this->workPhoto($user, $card, $m, $text, $chat);

            return;
        }
        if ($text === '') {
            return; // foto tanpa keterangan dan bukan balasan kartu kendala
        }
        if ($private && in_array(strtolower(explode('@', $text)[0]), ['/bantuan', '/help'], true)) {
            $this->api->send($chat, $this->help());

            return;
        }
        $command = strtolower(explode('@', preg_split('/\s+/', $text, 2)[0])[0]);
        $rest = trim((string) (preg_split('/\s+/', $text, 2)[1] ?? ''));
        if (in_array($command, ['/chronology', '/checklist', '/batal'], true)) {
            if (! $private) {
                $this->api->send($chat, e(__('Buka chat pribadi dengan bot untuk perintah ini.')), null, (int) $m['message_id']);
            } elseif ($command === '/chronology') {
                $this->chronology->start($user, $chat, $rest);
            } elseif ($command === '/checklist') {
                $this->checklist->open($user, $chat);
            } else {
                $this->chronology->cancel($user);
                Cache::forget(ChecklistFlow::REASON.$user->id);
                $this->api->send($chat, e(__('Dibatalkan.')));
            }

            return;
        }
        $reply = match ($command) {
            '/daftar' => Reports::open($private ? $user : null),
            '/respon' => Reports::response(),
            '/tertinggal' => Reports::lost(),
            '/anggota' => Gate::forUser($user)->allows('manage-tasks') ? Reports::members() : null,
            default => null,
        };
        if ($reply) {
            $this->api->send($chat, $reply);

            return;
        }
        if (str_starts_with($text, '/')) {
            return; // perintah lain bukan untuk bot ini
        }

        // Chat pribadi: jawaban atas pertanyaan bot (teks laporan Chronology / alasan Pending).
        if ($private && $this->chronology->waiting($user)) {
            $this->chronology->receive($user, $chat, $text);

            return;
        }
        if ($private && $this->checklist->waitingReason($user)) {
            $this->checklist->saveReason($user, $text);
            $this->api->call('setMessageReaction', ['chat_id' => $chat, 'message_id' => (int) $m['message_id'], 'reaction' => [['type' => 'emoji', 'emoji' => '👍']]]);

            return;
        }

        // Membalas kartu / pesan yang sudah tercatat: "copy" = Saya ambil, "done" = Selesai; barang tertinggal: "diambil nama" / "dikirim resi" / "dilepas".
        if ($replyId = $m['reply_to_message']['message_id'] ?? null) {
            $card = TelegramCard::where(['chat_id' => $chat, 'message_id' => $replyId])->first();
            if ($card?->lost_item_id && preg_match('~^(diambil|dikirim|dilepas|disimpan)\b[\s:,\-]*(.*)$~isu', $text, $match)) {
                $this->lostStatus($user, $card, strtolower($match[1]), trim($match[2]), $chat, (int) $m['message_id']);

                return;
            }
            if ($card && ($action = $this->quick->replyAction($text))) {
                $this->act($user, $card, $action, $chat, (int) $m['message_id']);

                return;
            }
        }

        $this->record($user, $text, $chat, (int) $m['message_id'], $private, $m['photo'] ?? null);
    }

    /** Ketik bebas: permintaan langsung dicatat (kartu + 🗑 Batal); kendala menunggu ketukan "Catat kendala" supaya obrolan biasa tidak jadi kendala. */
    private function record(User $user, string $text, int $chat, int $messageId, bool $private, ?array $photo = null): void
    {
        $settings = app(ChecklistSettings::class);
        if (! $settings->quickEntry('quick_entry')) {
            return;
        }
        $read = $this->quick->read($text);
        $gate = Gate::forUser($user);

        if ($read['type'] === 'lost') {
            $this->recordLost($user, $read, $chat, $messageId, $private, $photo);
        } elseif ($read['type'] === 'request' && $gate->allows('guest-requests') && $settings->quickEntry('quick_requests')) {
            if (! $read['room']) {
                $this->api->send($chat, '⚠️ '.e($read['warnings'][0] ?? ''), null, $messageId);

                return;
            }
            $created = $this->recorder->requests($user, $read, $text);
            // Pesan asli dikenali saat dibalas "copy" / "done" (ke permintaan pertama; yang lain ikut lewat ReplyAction).
            TelegramCard::create(['chat_id' => $chat, 'message_id' => $messageId, 'origin' => true, 'guest_request_id' => $created->first()->id]);
            // Kartu otomatis ke grup (TelegramNotifier); dari chat lain / pribadi, beri tahu pencatatnya.
            if ($private || (string) $chat !== (string) config('services.telegram.chat_id')) {
                $this->api->send($chat, '✅ '.e(__('Dicatat: Kamar :room · :items', ['room' => $read['room']->number, 'items' => $created->map->label()->join(', ')])), null, $messageId);
            }
        } elseif ($read['type'] === 'issue' && $read['room'] && $gate->allows('room-issues') && $settings->quickEntry('quick_issues')) {
            $token = bin2hex(random_bytes(6));
            Cache::put(self::PENDING_CACHE.$token, ['user' => $user->id, 'text' => $text], now()->addMinutes(30));
            $this->api->send($chat, '🛠 <b>'.e(__('Sepertinya kendala')).'</b> · '.e(__('Kamar').' '.$read['room']->number).($read['category'] ? ' · '.e($read['category']->name) : '')
                ."\n".e(__('Catat sebagai kendala?')), [[['text' => __('Catat kendala'), 'callback_data' => 'ik:rec:'.$token], ['text' => __('Abaikan'), 'callback_data' => 'ik:no:'.$token]]], $messageId);
        }
    }

    /** Foto bukti pekerjaan kendala dari Telegram: masuk ke riwayat kendala (satu catatan; album = satu catatan, maks 3 foto). @param array<string, mixed> $m */
    private function workPhoto(User $user, TelegramCard $card, array $m, string $caption, int $chat): void
    {
        $messageId = (int) $m['message_id'];
        $issue = RoomIssue::find($card->room_issue_id);
        $react = fn (string $emoji) => $this->api->call('setMessageReaction', ['chat_id' => $chat, 'message_id' => $messageId, 'reaction' => [['type' => 'emoji', 'emoji' => $emoji]]]);
        if (! $issue || ! Gate::forUser($user)->allows('work', $issue)) {
            $this->api->send($chat, '⚠️ '.e($issue?->isDone() ? __('Kendala ini sudah Done.') : __('Foto bukti hanya dari yang mengambil kendala ini (ketuk Saya ambil dulu).')), null, $messageId);

            return;
        }
        $album = isset($m['media_group_id']) ? 'telegram.album.'.$m['media_group_id'] : null;
        $update = $album && ($id = Cache::get($album)) ? RoomIssueUpdate::find($id) : null;
        if ($update && $update->photos()->count() >= RoomIssuePhotoService::MAX_PER_UPDATE) {
            return; // album lebih dari 3 foto: sisanya diabaikan
        }
        $bytes = $this->api->download((string) end($m['photo'])['file_id']);
        if (! $bytes) {
            $this->api->send($chat, '⚠️ '.e(__('Foto tidak bisa diunduh dari Telegram. Coba kirim lagi.')), null, $messageId);

            return;
        }
        $done = $caption !== '' && $this->quick->replyAction($caption) === 'done';
        $words = $caption !== '' && ! $this->quick->replyAction($caption);
        try {
            $update ??= RoomIssueUpdate::create(['room_issue_id' => $issue->id, 'user_id' => $user->id, 'department_id' => $user->checklistDepartmentId(), 'body' => $words ? $caption : __('Foto bukti pekerjaan.'), 'kind' => 'catatan']);
            app(RoomIssuePhotoService::class)->storeBytes($update, $bytes, $user);
        } catch (\Throwable) {
            $react('🤷');

            return;
        }
        $album && Cache::put($album, $update->id, now()->addMinutes(5));
        $done && $this->applyAction($user, $card, 'done');
        $react('👍');
    }

    /** Barang tertinggal ("1203 dompet tertinggal", boleh dengan foto): langsung dicatat; kartu ke grup dikirim TelegramNotifier. @param array<string, mixed> $read @param list<array<string, mixed>>|null $photo */
    private function recordLost(User $user, array $read, int $chat, int $messageId, bool $private, ?array $photo): void
    {
        if (! $read['room']) {
            $this->api->send($chat, '⚠️ '.e($read['warnings'][0] ?? ''), null, $messageId);

            return;
        }
        $path = null;
        if ($photo && ($bytes = $this->api->download((string) end($photo)['file_id']))) {
            try {
                $path = LostItemController::storePhoto(PhotoReencoder::fromString($bytes));
            } catch (\Throwable) {
                // bukan gambar yang bisa dibaca: dicatat tanpa foto
            }
        }
        $item = LostItem::create([
            'room_id' => $read['room']->id, 'description' => $read['description'] ?? __('Barang tertinggal (lihat foto)'), 'photo_path' => $path,
            'found_by' => $user->id, 'found_at' => now(), 'status' => LostItem::STATUS_STORED, 'source' => 'telegram',
        ]);
        TelegramCard::create(['chat_id' => $chat, 'message_id' => $messageId, 'origin' => true, 'lost_item_id' => $item->id]);
        if ($private || (string) $chat !== (string) config('services.telegram.chat_id')) {
            $this->api->send($chat, '✅ '.e(__('Dicatat: barang tertinggal · :place · :item', ['place' => $item->placeTitle(), 'item' => $item->description])), null, $messageId);
        }
    }

    /** Balasan pada kartu barang tertinggal: ubah status (diambil / dikirim wajib dengan nama penerima / resi). */
    private function lostStatus(User $user, TelegramCard $card, string $status, string $note, int $chat, int $messageId): void
    {
        $item = LostItem::find($card->lost_item_id);
        $needsNote = in_array($status, ['diambil', 'dikirim'], true) && $note === '';
        if (! $item || $needsNote) {
            $this->api->send($chat, $needsNote ? '⚠️ '.e(__('Tulis nama penerima / nomor resi, mis. "diambil Mr. Smith".')) : e(__('Barang tidak ditemukan.')), null, $messageId);

            return;
        }
        $resolved = $status !== LostItem::STATUS_STORED;
        $item->update(['status' => $status, 'resolution_note' => $note ?: null, 'resolved_by' => $resolved ? $user->id : null, 'resolved_at' => $resolved ? now() : null]);
        $this->notifier->refreshNow($item);
        $this->api->call('setMessageReaction', ['chat_id' => $chat, 'message_id' => $messageId, 'reaction' => [['type' => 'emoji', 'emoji' => '👍']]]);
    }

    /** @param array<string, mixed> $q */
    private function callback(array $q): void
    {
        $id = (string) $q['id'];
        $user = $this->user((int) ($q['from']['id'] ?? 0));
        if (! $user) {
            $this->api->answer($id, __('Hubungkan akun Telegram Anda dulu (aplikasi > Profil).'), true);

            return;
        }
        [$kind, $action, $key] = array_pad(explode(':', (string) ($q['data'] ?? ''), 3), 3, '');
        $chat = (int) ($q['message']['chat']['id'] ?? 0);
        $messageId = (int) ($q['message']['message_id'] ?? 0);

        if ($kind === 'ik') {
            $this->issueButton($id, $user, $action, $key, $chat, $messageId, $q['message'] ?? []);

            return;
        }
        if ($kind === 'cr') {
            $this->chronology->callback($user, $action, (int) $key, $chat, $messageId, $id);

            return;
        }
        if ($kind === 'cl') {
            $this->checklist->callback($user, $action, $key, $chat, $messageId, $id);

            return;
        }
        if ($kind === 'as') {
            $this->assign($id, $user, $action, $key, $chat, $messageId);

            return;
        }
        $model = $kind === 'rq' ? GuestRequest::find((int) $key) : RoomIssue::find((int) $key);
        if (! $model) {
            $this->api->answer($id, __('Tidak ditemukan (mungkin sudah dihapus).'), true);

            return;
        }
        if ($action === 'del') {
            if ($model instanceof GuestRequest && Gate::forUser($user)->allows('delete', $model)) {
                $model->delete(); // kartunya ikut terhapus (cascade); pesannya diganti
                $this->api->edit($chat, $messageId, '🗑 '.e(__('Permintaan dibatalkan.')));
                $this->api->answer($id, __('Dibatalkan.'));
            } else {
                $this->api->answer($id, __('Hanya pencatatnya (selama masih Baru) atau Administrator yang bisa membatalkan.'), true);
            }

            return;
        }
        $card = new TelegramCard($kind === 'rq' ? ['guest_request_id' => $model->id] : ['room_issue_id' => $model->id]);
        $this->api->answer($id, $this->applyAction($user, $card, $action === 'done' ? 'done' : 'claim') ? '✅' : __('Tidak bisa: sudah diambil / diselesaikan, bukan tugas Anda, atau Anda sendiri pelapornya.'), false);
    }

    /** Tombol "Tugaskan ke…" di pesan peringatan HOD: `as:rq|is:ID` = tampilkan pilihan, `as:rq|is:ID:USER` = tugaskan, `:0` = kembali. */
    private function assign(string $callbackId, User $by, string $kind, string $key, int $chat, int $messageId): void
    {
        [$modelId, $target] = array_pad(explode(':', $key, 2), 2, null);
        $model = $kind === 'rq' ? GuestRequest::find((int) $modelId) : RoomIssue::find((int) $modelId);
        $mayAssign = $model && ($by->role->overridesReview() || ($by->role === \App\Enums\Role::Hod && in_array($model->assigned_department_id, $by->hodDepartmentIds(), true)));
        if (! $mayAssign) {
            $this->api->answer($callbackId, $model ? __('Hanya HOD departemen tujuan, HR, atau Administrator yang bisa menugaskan.') : __('Tidak ditemukan (mungkin sudah dihapus).'), true);

            return;
        }
        [$html, $keyboard] = $kind === 'rq' ? Cards::request($model) : Cards::issue($model);
        if ($target === null) { // pilihan orang: semua departemen, departemen tujuan lebih dulu
            $people = User::with('employee.department')->whereNotNull('telegram_id')->get()->filter->canUseApp()
                ->sortBy(fn (User $u) => [$u->employee?->department_id === $model->assigned_department_id ? 0 : 1, $u->employee?->department?->name, $u->name()])
                ->map(fn (User $u) => ['text' => mb_substr($u->name().' · '.($u->employee?->department?->name ?? '—'), 0, 40), 'callback_data' => 'as:'.$kind.':'.$model->id.':'.$u->id])
                ->take(90)->values()->chunk(2)->map->values()->all();
            $people[] = [['text' => '‹ '.__('Batal'), 'callback_data' => 'as:'.$kind.':'.$model->id.':0']];
            $this->api->edit($chat, $messageId, $html."\n\n".e(__('Tugaskan ke siapa?')), $people);
            $this->api->answer($callbackId);

            return;
        }
        $to = (int) $target ? User::find((int) $target) : null;
        if (! $to) { // Batal: kembali ke kartu dengan tombol Tugaskan
            $keyboard[] = [['text' => __('Tugaskan ke…'), 'callback_data' => 'as:'.$kind.':'.$model->id]];
            $this->api->edit($chat, $messageId, $html, $keyboard);
            $this->api->answer($callbackId);

            return;
        }
        if (! $this->replies->assign($by, $to, $model)) {
            $this->api->answer($callbackId, __('Sudah diambil orang lain.'), true);
            $this->api->edit($chat, $messageId, Cards::{$kind === 'rq' ? 'request' : 'issue'}($model->fresh())[0]);

            return;
        }
        $this->api->edit($chat, $messageId, '✅ '.e(__('Ditugaskan ke :name', ['name' => $to->name()])));
        // Pesan pribadi ke yang ditugaskan, dengan tombol Selesai.
        [$cardHtml, $cardKeys] = $kind === 'rq' ? Cards::request($model->fresh()) : Cards::issue($model->fresh());
        $sent = $this->api->send($to->telegram_id, '📌 <b>'.e(__(':by menugaskan Anda', ['by' => $by->name()])).'</b>'."\n".$cardHtml, $cardKeys ?: null);
        isset($sent['message_id']) && TelegramCard::firstOrCreate(['chat_id' => $to->telegram_id, 'message_id' => $sent['message_id']], [$kind === 'rq' ? 'guest_request_id' : 'room_issue_id' => $model->id]);
        $this->api->answer($callbackId, '✅');
    }

    private function issueButton(string $callbackId, User $user, string $action, string $token, int $chat, int $messageId, array $callbackMessage = []): void
    {
        $pending = Cache::pull(self::PENDING_CACHE.$token);
        if (! $pending || $pending['user'] !== $user->id) {
            $this->api->answer($callbackId, $pending ? __('Hanya pengirim pesan yang bisa memutuskan.') : __('Sudah tidak berlaku.'), true);
            $pending && Cache::put(self::PENDING_CACHE.$token, $pending, now()->addMinutes(30));

            return;
        }
        if ($action !== 'rec') {
            $this->api->edit($chat, $messageId, e(__('Diabaikan.')));
            $this->api->answer($callbackId);

            return;
        }
        $read = $this->quick->read($pending['text']);
        if ($read['type'] !== 'issue' || ! $read['room'] || ! Gate::forUser($user)->allows('room-issues')) {
            $this->api->answer($callbackId, __('Tidak bisa dicatat.'), true);

            return;
        }
        $issue = $this->recorder->issue($user, $read, $pending['text']);
        // Kartu kerja (tombol Saya ambil / Selesai) dikirim sekali oleh TelegramNotifier; pertanyaan ini hanya jadi pemberitahuan.
        $this->api->edit($chat, $messageId, '✅ '.e(__('Kendala dicatat').' · '.$issue->placeTitle().($issue->category ? ' · '.$issue->category->name : '')));
        // Pesan asli pengguna dikenali saat dibalas "copy" / "done".
        $original = $callbackMessage['reply_to_message']['message_id'] ?? null;
        $original && TelegramCard::firstOrCreate(['chat_id' => $chat, 'message_id' => $original], ['room_issue_id' => $issue->id, 'origin' => true]);
        $this->api->answer($callbackId, '✅');
    }

    /** Balasan teks pada kartu / pesan asli. */
    private function act(User $user, TelegramCard $card, string $action, int $chat, int $messageId): void
    {
        $ok = $this->applyAction($user, $card, $action);
        // Reaksi di pesan balasan (👍 berhasil, 🤷 tidak bisa); gagal diam-diam bila grup tidak mengizinkan reaksi.
        $this->api->call('setMessageReaction', ['chat_id' => $chat, 'message_id' => $messageId, 'reaction' => [['type' => 'emoji', 'emoji' => $ok ? '👍' : '🤷']]]);
    }

    /** Jalankan Saya ambil / Selesai lewat ReplyAction (hak dan aturan sama dengan tombol web; ReplyAction juga memperbarui kartu). */
    private function applyAction(User $user, TelegramCard $card, string $action): bool
    {
        $won = $this->replies->apply($user, new ChatMessage(['guest_request_id' => $card->guest_request_id, 'room_issue_id' => $card->room_issue_id]), $action);

        return $won['requests']->isNotEmpty() || $won['issue'] !== null;
    }

    /** `/start KODE` di chat pribadi: tautkan akun Telegram ke pengguna pemilik kode. */
    private function link(int $chat, int $from, string $code): void
    {
        $userId = $code !== '' ? Cache::pull(self::LINK_CACHE.$code) : null;
        // Tautan buatan HR (Kelola > Tautan Telegram): tersimpan di basis data, sekali pakai.
        $userId ??= $code !== '' ? User::where('telegram_link_code', $code)->where('telegram_link_expires_at', '>', now())->value('id') : null;
        if (! $userId) {
            $this->api->send($chat, $code === '' ? $this->help() : e(__('Kode tidak berlaku. Buat kode baru di aplikasi > Profil > Hubungkan Telegram.')));

            return;
        }
        $user = User::find($userId);
        if (! $user || ! $user->canUseApp()) {
            $this->api->send($chat, e(__('Akun aplikasi tidak aktif.')));

            return;
        }
        if (User::where('telegram_id', $from)->where('id', '!=', $user->id)->exists()) {
            $this->api->send($chat, e(__('Akun Telegram ini sudah terhubung ke pengguna lain. Putuskan dulu di aplikasi pengguna itu.')));

            return;
        }
        $user->update(['telegram_id' => $from]);
        User::whereKey($user->id)->update(['telegram_link_code' => null, 'telegram_link_expires_at' => null]);
        $this->api->send($chat, '✅ '.e(__('Terhubung sebagai :name.', ['name' => $user->name()]))."\n\n".$this->help());
    }

    private function user(int $telegramId): ?User
    {
        $user = $telegramId > 0 ? User::where('telegram_id', $telegramId)->first() : null;

        return $user && $user->canUseApp() ? $user : null;
    }

    private function help(): string
    {
        return '<b>'.e(__('Bot eL Checklist')).'</b>'
            ."\n".e(__('Ketik seperti biasa di grup: "1206 req 2 mineral water" dicatat sebagai permintaan tamu, "305 AC tidak dingin" ditawarkan sebagai kendala.'))
            ."\n".e(__('Balas kartu atau pesan permintaan dengan "copy" (Saya ambil) atau "done" (Selesai), atau ketuk tombolnya.'))
            ."\n".e(__('Foto bukti pekerjaan kendala: balas kartu kendala dengan foto (keterangan boleh diisi; "done" sekaligus menyelesaikan). Hanya yang mengambil kendala itu.'))
            ."\n".e(__('Chat pribadi: /checklist (isi checklist hari ini dengan tombol), /chronology (tempel teks laporan, lalu dikirim ke grup), /batal.'))
            ."\n".e(__('Barang tertinggal: kirim foto dengan keterangan "1203 dompet hitam tertinggal"; ubah statusnya dengan membalas kartu: "diambil Mr. Smith", "dikirim resi JNE123", "dilepas".'))
            ."\n".e(__('Perintah: /daftar (belum selesai + siapa yang mengambil), /respon (kecepatan hari ini), /tertinggal (barang disimpan), /anggota (siapa sudah tertaut; HR / Admin).'));
    }
}
