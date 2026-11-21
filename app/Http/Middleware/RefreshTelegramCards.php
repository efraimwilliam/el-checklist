<?php

namespace App\Http\Middleware;

use App\Models\GuestRequest;
use App\Models\RoomIssue;
use App\Telegram\TelegramNotifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sesudah jawaban terkirim: permintaan / kendala yang berubah lewat rute mana pun (web, aplikasi Android; parameter `guestRequest` /
 * `issue`) memperbarui kartunya di Telegram. Tidak berbuat apa pun bila bot belum dikonfigurasi.
 */
class RefreshTelegramCards
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($request->isMethodSafe() || $response->getStatusCode() >= 400 || blank(config('services.telegram.token'))) {
            return;
        }
        $model = $request->route('guestRequest') ?? $request->route('issue');
        if ($model instanceof GuestRequest || $model instanceof RoomIssue) {
            app(TelegramNotifier::class)->refreshNow($model);
        }
    }
}
