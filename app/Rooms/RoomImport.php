<?php

namespace App\Rooms;

use App\Models\Room;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Impor daftar kamar dari Excel (.xlsx): baris pertama judul kolom. Kolom nomor kamar wajib; lantai, gedung, dan tipe opsional.
 * Nomor kamar yang sudah ada diperbarui, yang baru ditambahkan; kamar yang tidak ada di berkas tidak diubah.
 * Bila kolom lantai kosong, lantai ditebak dari nomor (305 -> 3, 1204 -> 12).
 */
final class RoomImport
{
    public const HEADERS = ['Nomor kamar', 'Lantai', 'Gedung', 'Tipe'];

    /** Nama judul kolom yang dikenali (huruf kecil) untuk tiap isian. */
    private const ALIASES = [
        'number' => ['nomor kamar', 'no kamar', 'no. kamar', 'kamar', 'nomor', 'room', 'room no', 'room number', 'no'],
        'floor' => ['lantai', 'floor', 'level'],
        'building' => ['gedung', 'building', 'blok', 'block', 'wing', 'tower'],
        'type' => ['tipe', 'tipe kamar', 'type', 'room type', 'jenis'],
    ];

    /**
     * @return array{create: list<array{line: int, fields: array<string, ?string>}>, update: list<array{line: int, id: int, fields: array<string, ?string>}>, same: int, errors: list<array{line: int, message: string}>}
     *
     * @throws InvalidRoomFile
     */
    public function plan(string $path): array
    {
        try {
            $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);
        } catch (Throwable) {
            throw new InvalidRoomFile(__('Berkas tidak bisa dibaca. Pastikan berformat Excel (.xlsx).'));
        }

        $header = array_map(fn ($value) => mb_strtolower(trim((string) $value)), array_shift($rows) ?? []);
        $columns = [];
        foreach (self::ALIASES as $field => $names) {
            foreach ($header as $index => $name) {
                if (in_array($name, $names, true)) {
                    $columns[$field] = $index;
                    break;
                }
            }
        }
        if (! isset($columns['number'])) {
            throw new InvalidRoomFile(__('Kolom "Nomor kamar" tidak ditemukan di baris judul. Unduh contoh berkas dan isi sesuai kolomnya.'));
        }

        $existing = Room::all()->keyBy(fn (Room $room) => mb_strtolower($room->number));
        $plan = ['create' => [], 'update' => [], 'same' => 0, 'errors' => []];
        $seen = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $cell = fn (string $field) => isset($columns[$field]) ? $this->clean($row[$columns[$field]] ?? null) : null;
            $number = $cell('number');

            if ($number === null && $cell('floor') === null && $cell('type') === null) {
                continue; // baris kosong
            }
            if ($number === null) {
                $plan['errors'][] = ['line' => $line, 'message' => __('Nomor kamar kosong.')];

                continue;
            }
            if (mb_strlen($number) > 30) {
                $plan['errors'][] = ['line' => $line, 'message' => __('Nomor kamar maksimal 30 karakter.')];

                continue;
            }
            $key = mb_strtolower($number);
            if (isset($seen[$key])) {
                $plan['errors'][] = ['line' => $line, 'message' => __('Nomor kamar :number sudah ada di baris lain.', ['number' => $number])];

                continue;
            }
            $seen[$key] = true;

            $fields = [
                'number' => $number,
                'floor' => $cell('floor') ?? $this->guessFloor($number),
                'building' => $cell('building'),
                'type' => $cell('type'),
            ];
            if (mb_strlen((string) $fields['floor']) > 30 || mb_strlen((string) $fields['building']) > 60 || mb_strlen((string) $fields['type']) > 60) {
                $plan['errors'][] = ['line' => $line, 'message' => __('Lantai maksimal 30, Gedung dan Tipe maksimal 60 karakter.')];

                continue;
            }

            $room = $existing->get($key);
            if (! $room) {
                $plan['create'][] = ['line' => $line, 'fields' => $fields];
            } elseif ($room->floor !== $fields['floor'] || $room->building !== $fields['building'] || $room->type !== $fields['type'] || ! $room->is_active) {
                $plan['update'][] = ['line' => $line, 'id' => $room->id, 'fields' => $fields];
            } else {
                $plan['same']++;
            }
        }

        return $plan;
    }

    /**
     * @return array{created: int, updated: int}
     *
     * @throws InvalidRoomFile
     */
    public function apply(string $path): array
    {
        $plan = $this->plan($path);
        if ($plan['errors']) {
            throw new InvalidRoomFile(__('Berkas masih punya kesalahan. Perbaiki dulu, lalu periksa ulang.'));
        }

        DB::transaction(function () use ($plan) {
            $sort = (int) Room::max('sort');
            foreach ($plan['create'] as $item) {
                Room::create($item['fields'] + ['is_active' => true, 'sort' => ++$sort]);
            }
            foreach ($plan['update'] as $item) {
                Room::whereKey($item['id'])->update(['floor' => $item['fields']['floor'], 'building' => $item['fields']['building'], 'type' => $item['fields']['type'], 'is_active' => true]);
            }
        });

        return ['created' => count($plan['create']), 'updated' => count($plan['update'])];
    }

    private function clean(mixed $value): ?string
    {
        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value; // 305.0 dari Excel -> "305"
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function guessFloor(string $number): ?string
    {
        if (! ctype_digit($number) || strlen($number) < 3) {
            return null;
        }

        return ltrim(substr($number, 0, strlen($number) - 2), '0') ?: null;
    }
}
