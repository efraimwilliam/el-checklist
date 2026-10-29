<?php

namespace App\Checklists;

use App\Enums\ItemStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Isian form checklist. Checklist hanya bisa disimpan lengkap: setiap tugas berstatus, dan Pending wajib beralasan
 * (bila CHECKLIST_PENDING_NOTE_REQUIRED). Kunci tugas = id tugas (checklist baru) atau id item (checklist yang diperbarui).
 */
class ChecklistInput
{
    /**
     * @param  list<int>  $keys  kunci tugas yang boleh diisi
     * @return array{items: array<int, array{status: ?string, note: ?string}>, note: ?string, checked_by: ?string, extras: list<array{title: string, status: string, note: ?string}>}
     *
     * @throws ValidationException
     */
    public static function validate(Request $request, array $keys): array
    {
        $data = $request->validate([
            'items' => ['array'],
            'items.*.status' => ['nullable', Rule::enum(ItemStatus::class)],
            'items.*.note' => ['nullable', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'checked_by' => ['nullable', 'string', 'max:255'],
            'extras' => ['array', 'max:20'],
            'extras.*.title' => ['nullable', 'string', 'max:500'],
            'extras.*.status' => ['nullable', Rule::enum(ItemStatus::class)],
            'extras.*.note' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var Collection<int, array{status?: ?string, note?: ?string}> $items */
        $items = collect($data['items'] ?? [])->only($keys);

        $errors = [];
        foreach ($keys as $key) {
            $status = $items[$key]['status'] ?? null;
            if (! $status) {
                $errors["items.{$key}.status"] = __('Pilih status tugas ini.');
            } elseif ($status === ItemStatus::Pending->value && config('checklist.pending_note_required') && blank($items[$key]['note'] ?? null)) {
                $errors["items.{$key}.note"] = __('Tugas Pending wajib diberi alasan.');
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'items' => $items->map(fn ($item) => ['status' => $item['status'], 'note' => self::clean($item['note'] ?? null)])->all(),
            'note' => self::clean($data['note'] ?? null),
            'checked_by' => self::clean($data['checked_by'] ?? null),
            'extras' => collect($data['extras'] ?? [])->filter(fn ($extra) => filled($extra['title'] ?? null))->map(fn ($extra) => [
                'title' => trim($extra['title']),
                'status' => $extra['status'] ?? ItemStatus::Done->value,
                'note' => self::clean($extra['note'] ?? null),
            ])->values()->all(),
        ];
    }

    private static function clean(?string $value): ?string
    {
        return blank($value) ? null : trim($value);
    }
}
