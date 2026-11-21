<?php

namespace App\Http\Controllers;

use App\Telegram\TelegramApi;
use App\Telegram\TelegramHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Webhook bot Telegram (tanpa login; dijaga header rahasia) dan tautan akun dari halaman Profil. */
class TelegramController extends Controller
{
    /** Telegram memanggil ini untuk tiap update. Selalu 200 supaya Telegram tidak mengulang update yang bermasalah. */
    public function webhook(Request $request, TelegramHandler $handler): JsonResponse
    {
        $secret = (string) config('services.telegram.secret');
        abort_unless(filled(config('services.telegram.token')) && $secret !== '' && hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 404);

        try {
            $handler->handle((array) $request->json()->all());
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['ok' => true]);
    }

    /** Buat kode tautan sekali pakai (10 menit); halaman Profil menampilkan tautan t.me yang membawanya ke bot. */
    public function code(Request $request, TelegramApi $api): RedirectResponse
    {
        abort_unless($api->enabled(), 404);
        $code = Str::lower(Str::random(10));
        Cache::put(TelegramHandler::LINK_CACHE.$code, $request->user()->id, now()->addMinutes(10));

        return redirect()->route('profile.edit')->with('telegram_code', $code);
    }

    public function unlink(Request $request): RedirectResponse
    {
        \App\Models\User::whereKey($request->user()->id)->update(['telegram_id' => null]);

        return redirect()->route('profile.edit')->with('status', __('Telegram diputuskan.'));
    }
}
