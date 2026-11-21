<?php

namespace App\Telegram;

use App\Chat\QuickEntry;
use App\Chat\QuickRecorder;
use App\Chat\ReplyAction;
use App\Checklists\ChecklistSettings;
use App\Models\ChatMessage;
use App\Models\GuestRequest;
use App\Models\RoomIssue;
use App\Models\TelegramCard;
use App\Models\User;
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
    ) {}

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->callback($update['callback_query']);
        } elseif (isset($update['message']['text'])) {
            $this->message($update['message']);
        }
    }

    /** @param array<string, mixed> $m */
    private function message(array $m): void
    {
        $chat = (int) $m['chat']['id'];
        $text = trim((string) $m['text']);
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
        if ($private && in_array(strtolower(explode('@', $text)[0]), ['/bantuan', '/help'], true)) {
            $this->api->send($chat, $this->help());

            return;
        }
        if (str_starts_with($text, '/')) {
            return; // perintah lain bukan untuk bot ini
        }

        // Membalas kartu / pesan yang sudah tercatat: "copy" = Saya ambil, "done" = Selesai.
        if ($replyId = $m['reply_to_message']['message_id'] ?? null) {
            $card = TelegramCard::where(['chat_id' => $chat, 'message_id' => $replyId])->first();
            if ($card && ($action = $this->quick->replyAction($text))) {
                $this->act($user, $card, $action, $chat, (int) $m['message_id']);

                return;
            }
        }

        $this->record($user, $text, $chat, (int) $m['message_id'], $private);
    }

    /** Ketik bebas: permintaan langsung dicatat (kartu + 🗑 Batal); kendala menunggu ketukan "Catat kendala" supaya obrolan biasa tidak jadi kendala. */
    private function record(User $user, string $text, int $chat, int $messageId, bool $private): void
    {
        $settings = app(ChecklistSettings::class);
        if (! $settings->quickEntry('quick_entry')) {
            return;
        }
        $read = $this->quick->read($text);
        $gate = Gate::forUser($user);

        if ($read['type'] === 'request' && $gate->allows('guest-requests') && $settings->quickEntry('quick_requests')) {
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
            $this->issueButton($id, $user, $action, $key, $chat, $messageId);

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
        $this->api->answer($id, $this->applyAction($user, $card, $action === 'done' ? 'done' : 'claim') ? '✅' : __('Tidak bisa: sudah diambil / diselesaikan atau bukan tugas Anda.'), false);
    }

    private function issueButton(string $callbackId, User $user, string $action, string $token, int $chat, int $messageId): void
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
        [$html, $keyboard] = Cards::issue($issue);
        $this->api->edit($chat, $messageId, $html, $keyboard);
        TelegramCard::firstOrCreate(['chat_id' => $chat, 'message_id' => $messageId], ['room_issue_id' => $issue->id]);
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
            ."\n".e(__('Balas kartu atau pesan permintaan dengan "copy" (Saya ambil) atau "done" (Selesai), atau ketuk tombolnya.'));
    }
}
