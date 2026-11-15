<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu kata / frasa di kamus ketik bebas (App\Chat\QuickEntry). Ditulis biasa (huruf kecil), bukan regex: spasi di tengah boleh
 * ada atau tidak ("tamu di kamar" = "tamu dikamar"), "*" di akhir = boleh diikuti huruf apa pun ("towel*" = towels), akhiran
 * -nya / -ny / -kan / -in boleh menempel ("showernya"), dan kata harus berdiri sendiri ("ac" tidak cocok di dalam "acara").
 */
class QuickTerm extends Model
{
    public const GROUPS = ['request_word', 'item', 'issue', 'urgent', 'ignore'];

    protected $fillable = ['group', 'target', 'term'];

    /** Regex untuk satu kata kamus (tanpa pembatas ~). */
    public static function pattern(string $term): string
    {
        $wildcard = str_ends_with($term, '*');
        $words = preg_split('~\s+~u', trim(rtrim($term, '*')));
        $body = implode('\s*', array_map(fn ($word) => preg_quote($word, '~'), $words));

        // Akhiran yang sering menempel di obrolan ("showernya", "lampuny", "dihollywoodkan") tetap cocok.
        return '(?<![\p{L}\d])'.$body.($wildcard ? '\p{L}*' : '(?:nya|ny|kan|in)?').'(?![\p{L}])';
    }

    /** Rapikan masukan Administrator: huruf kecil, spasi tunggal, "*" hanya di akhir. */
    public static function normalize(string $term): string
    {
        $term = mb_strtolower(trim(preg_replace('~\s+~u', ' ', $term)));
        $wildcard = str_ends_with($term, '*');

        return trim(str_replace('*', '', $term)).($wildcard ? '*' : '');
    }
}
