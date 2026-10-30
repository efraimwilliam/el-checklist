<?php

namespace App\Checklists;

use App\Enums\ItemStatus;
use App\Models\ChecklistItemParameter;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Jenis parameter isian dan aturannya.
 * status  = Done / Ongoing / Pending (bawaan). number = angka, dengan satuan dan batas min / max.
 * meter   = angka stand meter (dihitung seperti angka). option = pilihan, nilai normal menentukan Done.
 * checkbox = centang (dicentang = sudah). text = teks bebas.
 * Status tugas dihitung dari semua parameter: ada yang Pending -> Pending; ada Ongoing -> Ongoing; semua terisi dan normal -> Done.
 */
final class ParameterRules
{
    public const STATUS = 'status';

    public const NUMBER = 'number';

    public const METER = 'meter';

    public const OPTION = 'option';

    public const CHECKBOX = 'checkbox';

    public const TEXT = 'text';

    public const TYPES = [self::STATUS, self::NUMBER, self::METER, self::OPTION, self::CHECKBOX, self::TEXT];

    public const NUMERIC = [self::NUMBER, self::METER];

    public const MAX_PARAMETERS = 30;

    /** @return array<string, string> Jenis => nama tampilan (sudah diterjemahkan). */
    public static function labels(): array
    {
        return [
            self::STATUS => __('Status (Done, Ongoing, Pending)'),
            self::NUMBER => __('Angka'),
            self::METER => __('Stand meter (angka kumulatif)'),
            self::OPTION => __('Pilihan'),
            self::CHECKBOX => __('Centang (sudah dikerjakan)'),
            self::TEXT => __('Teks bebas'),
        ];
    }

    /** Aturan validasi untuk daftar parameter di form tugas. @return array<string, array<int, mixed>> */
    public static function editorRules(): array
    {
        return [
            'parameters' => ['required', 'array', 'min:1', 'max:'.self::MAX_PARAMETERS],
            'parameters.*.name' => ['required', 'string', 'max:120'],
            'parameters.*.type' => ['required', Rule::in(self::TYPES)],
            'parameters.*.unit' => ['nullable', 'string', 'max:30'],
            'parameters.*.min_value' => ['nullable', 'numeric'],
            'parameters.*.max_value' => ['nullable', 'numeric'],
            'parameters.*.options' => ['nullable', 'string', 'max:500'],
            'parameters.*.normal_option' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Baris parameter yang sudah dirapikan (hanya kolom yang dipakai jenisnya). Melempar ValidationException bila batas / pilihan keliru.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array{name: string, type: string, unit: ?string, min_value: ?string, max_value: ?string, options: ?string, normal_option: ?string}>
     *
     * @throws ValidationException
     */
    public static function normalize(array $rows): array
    {
        $result = [];
        foreach (array_values($rows) as $index => $row) {
            $type = (string) ($row['type'] ?? self::STATUS);
            $name = trim((string) ($row['name'] ?? ''));
            $key = "parameters.{$index}";

            $base = ['name' => $name, 'type' => $type, 'unit' => null, 'min_value' => null, 'max_value' => null, 'options' => null, 'normal_option' => null];
            if (in_array($type, self::NUMERIC, true)) {
                $min = self::number($row['min_value'] ?? null);
                $max = self::number($row['max_value'] ?? null);
                if ($min !== null && $max !== null && (float) $min > (float) $max) {
                    throw ValidationException::withMessages([$key => __('Batas bawah :name tidak boleh lebih besar dari batas atas.', ['name' => $name])]);
                }
                $base = [...$base, 'unit' => self::text($row['unit'] ?? null), 'min_value' => $min, 'max_value' => $max];
            }
            if ($type === self::OPTION) {
                $options = self::options($row['options'] ?? null);
                if (! $options) {
                    throw ValidationException::withMessages([$key => __('Isi pilihan untuk :name, dipisah koma.', ['name' => $name])]);
                }
                $normal = self::text($row['normal_option'] ?? null);
                if ($normal !== null && ! in_array($normal, $options, true)) {
                    throw ValidationException::withMessages([$key => __('Nilai normal :name harus salah satu pilihan.', ['name' => $name])]);
                }
                $base = [...$base, 'options' => implode(', ', $options), 'normal_option' => $normal];
            }
            $result[] = $base;
        }

        return $result;
    }

    /**
     * Nilai satu parameter dinilai. state: ok, ongoing, pending, empty. error = nilai tidak boleh disimpan.
     *
     * @return array{state: string, value: ?string, error: ?string}
     */
    public static function evaluate(ChecklistItemParameter $parameter, ?string $raw): array
    {
        $raw = trim((string) $raw);
        $empty = ['state' => 'empty', 'value' => null, 'error' => null];

        return match ($parameter->type) {
            self::STATUS => match ($raw) {
                '' => $empty,
                ItemStatus::Done->value => ['state' => 'ok', 'value' => $raw, 'error' => null],
                ItemStatus::Ongoing->value => ['state' => 'ongoing', 'value' => $raw, 'error' => null],
                ItemStatus::Pending->value => ['state' => 'pending', 'value' => $raw, 'error' => null],
                default => ['state' => 'empty', 'value' => null, 'error' => __('Pilih Done, Ongoing, atau Pending.')],
            },
            self::NUMBER, self::METER => self::number_($parameter, $raw, $empty),
            self::OPTION => self::option($parameter, $raw, $empty),
            self::CHECKBOX => in_array($raw, ['1', 'on', 'true'], true) ? ['state' => 'ok', 'value' => '1', 'error' => null] : $empty,
            self::TEXT => $raw === '' ? $empty : ['state' => 'ok', 'value' => mb_substr($raw, 0, 255), 'error' => null],
            default => $empty,
        };
    }

    /**
     * Status tugas dari semua parameternya. Tanpa parameter aktif = belum diisi.
     *
     * @param  Collection<int, ChecklistItemParameter>  $parameters
     */
    public static function status(Collection $parameters): ?ItemStatus
    {
        $states = $parameters->map(fn (ChecklistItemParameter $parameter) => self::stateOf($parameter));
        if ($states->isEmpty()) {
            return null;
        }
        if ($states->contains('pending')) {
            return ItemStatus::Pending;
        }
        if ($states->contains('ongoing')) {
            return ItemStatus::Ongoing;
        }

        return $states->every(fn ($state) => $state === 'ok') ? ItemStatus::Done : null;
    }

    /** Keadaan parameter tersimpan (dipakai untuk status tugas). */
    public static function stateOf(ChecklistItemParameter $parameter): string
    {
        return self::evaluate($parameter, $parameter->value)['state'];
    }

    private static function number_(ChecklistItemParameter $parameter, string $raw, array $empty): array
    {
        if ($raw === '') {
            return $empty;
        }
        $normalized = str_replace(',', '.', $raw);
        if (! is_numeric($normalized)) {
            return ['state' => 'empty', 'value' => null, 'error' => __('Isi dengan angka, mis. 220 atau 220,5.')];
        }
        $number = (float) $normalized;
        $outside = ($parameter->min_value !== null && $number < (float) $parameter->min_value)
            || ($parameter->max_value !== null && $number > (float) $parameter->max_value);

        return ['state' => $outside ? 'pending' : 'ok', 'value' => $normalized, 'error' => null];
    }

    private static function option(ChecklistItemParameter $parameter, string $raw, array $empty): array
    {
        if ($raw === '') {
            return $empty;
        }
        if (! in_array($raw, self::options($parameter->options), true)) {
            return ['state' => 'empty', 'value' => null, 'error' => __('Pilih salah satu pilihan yang tersedia.')];
        }
        $normal = $parameter->normal_option;

        return ['state' => $normal === null || $normal === $raw ? 'ok' : 'pending', 'value' => $raw, 'error' => null];
    }

    /** @return list<string> */
    public static function options(?string $text): array
    {
        return collect(explode(',', (string) $text))->map(fn ($option) => trim($option))->filter()->unique()->values()->all();
    }

    private static function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private static function number(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) (float) $value;
    }
}
