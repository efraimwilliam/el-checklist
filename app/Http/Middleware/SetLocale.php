<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/** Bahasa per pengguna (disimpan di sesi): id (bawaan) atau en. Teks sumber aplikasi berbahasa Indonesia. */
class SetLocale
{
    public const LOCALES = ['id', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale');
        $locale = in_array($locale, self::LOCALES, true) ? $locale : config('app.locale');
        App::setLocale($locale);
        CarbonImmutable::setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
