<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

/**
 * Rentang tanggal dari query `from` dan `to` (Y-m-d). Bawaan: `$defaultDays` hari sampai hari ini. Dibalik bila terbalik,
 * dibatasi `$maxDays` hari, dan tidak boleh sampai ke depan.
 */
final class DateRange
{
    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function fromRequest(Request $request, int $defaultDays, int $maxDays): array
    {
        $today = CarbonImmutable::today();
        $to = self::parse($request->query('to')) ?? $today;
        $to = $to->gt($today) ? $today : $to;
        $from = self::parse($request->query('from')) ?? $to->subDays($defaultDays - 1);
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ((int) $from->diffInDays($to) + 1 > $maxDays) {
            $from = $to->subDays($maxDays - 1);
        }

        return [$from, $to];
    }

    /** Teks rentang untuk tampilan: satu hari cukup tanggalnya ("30 Okt 2026"), dua tanggal dipisah tanda hubung. */
    public static function label(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return $from->isSameDay($to)
            ? $from->translatedFormat('d M Y')
            : $from->translatedFormat('d M Y').' – '.$to->translatedFormat('d M Y');
    }

    private static function parse(mixed $value): ?CarbonImmutable
    {
        try {
            return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
