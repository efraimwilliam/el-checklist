<?php

namespace App\Checklists;

use App\Models\Employee;
use App\Models\Position;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Saran jabatan checklist dari teks jabatan di Absensi ("Room Attendant" cocok dengan "Room Attendant (RA)"). HOD tetap harus menyimpannya.
 * Urutan: alias pasti (config/position_aliases.php) → cocok persis → salah satu memuat yang lain (hanya bila tepat satu).
 * Awalan "DW" / "Casual" (pekerja harian) diabaikan, dan isi dalam kurung ikut dicoba ("DW - Housekeeping (RA)" cocok dengan RA).
 */
class PositionSuggester
{
    /** @param  Collection<int, Position>  $positions  jabatan di departemen pegawai */
    public function suggest(Employee $employee, Collection $positions): ?Position
    {
        $title = $this->normalize($employee->position_title);
        if ($title === '') {
            return null;
        }

        $stripped = $this->withoutEmployment($title);
        $inside = $this->inside($employee->position_title);

        foreach (array_unique([$title, $stripped]) as $key) {
            $alias = config('position_aliases')[$key] ?? null;
            $hit = $alias ? $positions->first(fn (Position $p) => $this->normalize($p->name) === $this->normalize($alias)) : null;
            if ($hit) {
                return $hit;
            }
        }

        // Nama jabatan dan singkatannya dicocokkan terpisah: "GSA (Guest Service Agent)" dikenali sebagai "GSA" maupun "Guest Service Agent".
        foreach (array_unique([$title, $stripped, ...$inside]) as $variant) {
            $exact = $positions->filter(fn (Position $p) => in_array($variant, $this->names($p), true));
            if ($exact->count() === 1) {
                return $exact->first();
            }
        }

        // Salah satu memuat yang lain (mis. "Cook" dan "Cook – Hot Kitchen"): hanya bila tepat satu yang cocok.
        foreach (array_unique([$title, $stripped, ...array_filter($inside, fn (string $v) => strlen($v) >= 4)]) as $variant) {
            $partial = $positions->filter(fn (Position $p) => collect($this->names($p))->contains(fn (string $name) => strlen($name) >= 4 && (str_contains($variant, $name) || str_contains($name, $variant))));
            if ($partial->count() === 1) {
                return $partial->first();
            }
        }

        return null;
    }

    /** @return list<string> nama di luar tanda kurung, lalu isi tanda kurung */
    private function names(Position $position): array
    {
        preg_match_all('/\((.*?)\)/', $position->name, $inside);

        return array_values(array_filter(array_unique([$this->normalize(preg_replace('/\(.*?\)/', ' ', $position->name)), ...array_map($this->normalize(...), $inside[1])])));
    }

    /** @return list<string> isi tanda kurung pada teks jabatan Absensi, sudah dirapikan */
    private function inside(?string $title): array
    {
        preg_match_all('/\((.*?)\)/', (string) $title, $inside);

        return array_values(array_filter(array_unique(array_map(fn (string $text) => $this->withoutEmployment($this->normalize($text)), $inside[1]))));
    }

    /** Awalan pekerja harian / casual tidak menentukan jabatannya. */
    private function withoutEmployment(string $normalized): string
    {
        return (string) preg_replace('/^(dw|casual|daily worker)\s+/', '', $normalized);
    }

    private function normalize(?string $value): string
    {
        return (string) Str::of((string) $value)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish();
    }
}
