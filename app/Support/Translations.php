<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/** Kamus untuk JavaScript: teks Indonesia → Inggris, hanya saat bahasa Inggris dipilih (lang/en.json). */
final class Translations
{
    /** @return array<string, string> */
    public static function forJs(): array
    {
        if (app()->getLocale() !== 'en') {
            return [];
        }

        return json_decode(File::get(lang_path('en.json')), true) ?? [];
    }
}
