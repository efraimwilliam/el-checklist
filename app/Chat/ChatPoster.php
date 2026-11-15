<?php

namespace App\Chat;

use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\User;
use App\Push\ChatPushNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Menulis pesan ke obrolan (dipakai ChatController) dan mengirim kartu dari form lain yang dibuka di dalam obrolan
 * (menu lampiran / perintah /p /k /c): form membawa `obrolan` = id percakapan; setelah tersimpan, kartunya dikirim ke sana.
 */
class ChatPoster
{
    /** Percakapan tujuan dari form (`obrolan`), hanya bila ada dan pengguna boleh mengirim ke sana (obrolan menyala). */
    public function target(Request $request): ?ChatThread
    {
        $id = (int) $request->input('obrolan', 0);
        $thread = $id > 0 ? ChatThread::find($id) : null;

        return $thread && $request->user() && Gate::forUser($request->user())->allows('post', $thread) ? $thread : null;
    }

    public function post(ChatThread $thread, User $user, ?string $body, ?string $photo = null, ?int $replyTo = null, string $type = 'text'): ChatMessage
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

    /** Satu pesan berisi kartu, mis. ['guest_request_id' => 5]. */
    public function card(ChatThread $thread, User $user, array $link): ChatMessage
    {
        $message = $this->post($thread, $user, null);
        $message->update($link);

        return $message;
    }

    /** Sesudah form di dalam obrolan tersimpan: halaman kecil yang menutup jendela form dan memuat pesan baru. */
    public function done(ChatThread $thread): RedirectResponse
    {
        return redirect()->route('chat.tool-done', $thread);
    }
}
