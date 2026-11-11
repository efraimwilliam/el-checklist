<?php

namespace App\Push;

use App\Checklists\ChecklistSettings;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\Cache;

/**
 * Kirim notifikasi (Web Push) untuk pesan obrolan baru ke perangkat peserta lain. Isi notifikasi sengaja singkat (siapa dan di mana,
 * tanpa isi pesan) karena pesan kerja bisa terbaca di layar kunci. Tidak dikirim ke pengirim, ke yang sedang membuka obrolan itu,
 * dan tidak sama sekali bila obrolan dimatikan Administrator.
 */
final class ChatPushNotifier
{
    public function __construct(private readonly PushSender $sender) {}

    /** Kunci cache "sedang membuka obrolan ini" (diperbarui polling halaman obrolan). */
    public static function presenceKey(int $threadId, int $userId): string
    {
        return "chat.present.{$threadId}.{$userId}";
    }

    public static function markPresent(int $threadId, int $userId): void
    {
        Cache::put(self::presenceKey($threadId, $userId), true, (int) config('webpush.presence_seconds'));
    }

    public function notify(int $threadId, int $messageId): int
    {
        if (! app(ChecklistSettings::class)->chatEnabled()) {
            return 0;
        }
        $message = ChatMessage::with('user.employee')->find($messageId);
        $thread = ChatThread::with(['participants.user.employee', 'issue.room', 'checklist.employee'])->find($threadId);
        if (! $message || ! $thread || $message->isSystem() || $message->isDeleted()) {
            return 0;
        }
        $sender = $message->user;

        $sent = 0;
        foreach ($thread->participants as $participant) {
            $user = $participant->user;
            if (! $user || $user->id === $message->user_id || ! $user->canUseApp() || Cache::has(self::presenceKey($thread->id, $user->id))) {
                continue;
            }
            $subscriptions = PushSubscription::where('user_id', $user->id)->get();
            if ($subscriptions->isEmpty()) {
                continue;
            }
            $payload = $this->payload($thread, $message, $sender?->name() ?? '—', $message->mentionsUser($user), $user);

            foreach ($subscriptions as $subscription) {
                $result = $this->sender->send($subscription, $payload);
                if ($result === PushSender::SENT) {
                    $sent++;
                    $subscription->forceFill(['last_used_at' => now()])->save();
                } elseif ($result === PushSender::GONE) {
                    $subscription->delete();
                }
            }
        }

        return $sent;
    }

    /** @return array{title: string, body: string, url: string, tag: string} */
    private function payload(ChatThread $thread, ChatMessage $message, string $senderName, bool $mentioned, $recipient): array
    {
        $isDirect = $thread->isDirect();
        $title = $isDirect ? $senderName : $thread->title($recipient);
        $body = match (true) {
            $mentioned && $isDirect => __('Menyebut Anda di pesan'),
            $mentioned => __(':name menyebut Anda', ['name' => $senderName]),
            $isDirect => __('Pesan baru'),
            default => __(':name mengirim pesan baru', ['name' => $senderName]),
        };

        return [
            'title' => $title,
            'body' => $body,
            'url' => route('chat.show', $thread, false),
            'tag' => 'chat-'.$thread->id,
        ];
    }
}
