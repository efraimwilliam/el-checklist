<?php

namespace App\Checklists;

use App\Models\Employee;
use App\Models\Position;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Saran jabatan checklist dari teks jabatan di Absensi ("Room Attendant" cocok dengan "Room Attendant (RA)"). HOD tetap harus menyimpannya. */
class PositionSuggester
{
    /** @param  Collection<int, Position>  $positions  jabatan di departemen pegawai */
    public function suggest(Employee $employee, Collection $positions): ?Position
    {
        $title = $this->normalize($employee->position_title);
        if ($title === '') {
            return null;
        }

        // Nama jabatan dan singkatannya dicocokkan terpisah: "GSA (Guest Service Agent)" dikenali sebagai "GSA" maupun "Guest Service Agent".
        $exact = $positions->filter(fn (Position $p) => in_array($title, $this->names($p), true));
        if ($exact->count() === 1) {
            return $exact->first();
        }

        // Salah satu memuat yang lain (mis. "Cook" dan "Cook – Hot Kitchen"): hanya bila tepat satu yang cocok.
        $partial = $positions->filter(fn (Position $p) => collect($this->names($p))->contains(fn (string $name) => strlen($name) >= 4 && (str_contains($title, $name) || str_contains($name, $title))));

        return $partial->count() === 1 ? $partial->first() : null;
    }

    /** @return list<string> nama di luar tanda kurung, lalu isi tanda kurung */
    private function names(Position $position): array
    {
        preg_match_all('/\((.*?)\)/', $position->name, $inside);

        return array_values(array_filter(array_unique([$this->normalize(preg_replace('/\(.*?\)/', ' ', $position->name)), ...array_map($this->normalize(...), $inside[1])])));
    }

    private function normalize(?string $value): string
    {
        return (string) Str::of((string) $value)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish();
    }
}
