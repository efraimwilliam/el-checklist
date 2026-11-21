<?php

namespace App\Telegram;

use App\Chronology\ReportRecorder;
use App\Chronology\ReportText;
use App\Models\ChronologyReport;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * Chronology Report dari bot (chat pribadi): /chronology lalu tempel teks laporan -> dibaca (ReportParser) -> draft + pratinjau ->
 * tombol "Finalisasi & kirim ke grup" / "Simpan draft" / "Batal". Hak dan aturan sama dengan web (gate + ChronologyReportPolicy).
 */
class ChronologyFlow
{
    public const STATE = 'telegram.chronology.';

    public function __construct(private readonly TelegramApi $api) {}

    public function waiting(User $user): bool
    {
        return Cache::has(self::STATE.$user->id);
    }

    public function cancel(User $user): void
    {
        Cache::forget(self::STATE.$user->id);
    }

    /** `/chronology` (boleh langsung diikuti teks laporan). */
    public function start(User $user, int $chat, string $text = ''): void
    {
        if (! Gate::forUser($user)->allows('chronology-reports')) {
            $this->api->send($chat, e(__('Chronology Report tidak tersedia untuk akun Anda.')));

            return;
        }
        if ($text !== '') {
            $this->receive($user, $chat, $text);

            return;
        }
        Cache::put(self::STATE.$user->id, true, now()->addMinutes(15));
        $this->api->send($chat, '📄 <b>Chronology Report</b>'."\n".e(__('Tempel teks laporannya di pesan berikutnya (Guest Name, Room Number, Nationality, Length of stay, Source of Booking, lalu poin 1. 2. 3.). Ketik /batal untuk membatalkan.')));
    }

    public function receive(User $user, int $chat, string $text): void
    {
        $result = ReportRecorder::draftFromText($user, $text);
        if (! $result['report']) {
            Cache::put(self::STATE.$user->id, true, now()->addMinutes(15));
            $this->api->send($chat, '⚠️ '.e(__('Belum terbaca: :items. Perbaiki lalu tempel lagi (atau /batal).', ['items' => implode(', ', $result['missing'])]))
                .($result['warnings'] ? "\n".e(implode("\n", $result['warnings'])) : ''));

            return;
        }
        $this->cancel($user);
        $report = $result['report'];
        $this->sendChunks($chat, ReportText::plain($report->load(['guests', 'entries', 'author.employee'])));
        $this->api->send($chat, '📝 '.e(__('Draft tersimpan. Kirim ke grup sekarang?')).($result['warnings'] ? "\n".e(implode("\n", $result['warnings'])) : ''), [[
            ['text' => __('Finalisasi & kirim ke grup'), 'callback_data' => 'cr:fin:'.$report->id],
        ], [
            ['text' => __('Simpan draft saja'), 'callback_data' => 'cr:keep:'.$report->id],
            ['text' => __('Batal (hapus)'), 'callback_data' => 'cr:del:'.$report->id],
        ]]);
    }

    public function callback(User $user, string $action, int $id, int $chat, int $messageId, string $callbackId): void
    {
        $report = ChronologyReport::with(['guests', 'entries', 'author.employee'])->find($id);
        $gate = Gate::forUser($user);
        if (! $report || ! $gate->allows('update', $report)) {
            $this->api->answer($callbackId, __('Hanya penulis yang bisa mengubah draft ini, dan hanya selama masih draft.'), true);

            return;
        }
        if ($action === 'del') {
            $gate->allows('delete', $report) ? $report->delete() : null;
            $this->api->edit($chat, $messageId, '🗑 '.e(__('Draft dihapus.')));
        } elseif ($action === 'keep') {
            $this->api->edit($chat, $messageId, '📝 '.e(__('Draft disimpan. Finalisasi dan kirim dari aplikasi (menu Operasional > Chronology Report).')));
        } else {
            $group = config('services.telegram.chat_id');
            if (blank($group)) {
                $this->api->answer($callbackId, __('Grup tujuan belum diatur (TELEGRAM_CHAT_ID).'), true);

                return;
            }
            $report->update(['status' => ChronologyReport::STATUS_FINAL, 'finalized_at' => now()]);
            $this->sendChunks($group, ReportText::plain($report->fresh(['guests', 'entries', 'author.employee'])));
            $this->api->edit($chat, $messageId, '✅ '.e(__('Laporan difinalisasi dan dikirim ke grup.')));
        }
        $this->api->answer($callbackId);
    }

    /** Teks panjang dipecah di batas baris (batas Telegram 4096 huruf). */
    private function sendChunks(int|string $chat, string $text): void
    {
        $chunk = '';
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (mb_strlen($chunk) + mb_strlen($line) + 1 > 3800 && $chunk !== '') {
                $this->api->send($chat, e($chunk));
                $chunk = '';
            }
            $chunk .= ($chunk === '' ? '' : "\n").mb_substr($line, 0, 3800);
        }
        $chunk !== '' && $this->api->send($chat, e($chunk));
    }
}
