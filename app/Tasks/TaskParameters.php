<?php

namespace App\Tasks;

use App\Checklists\ParameterRules;
use App\Models\ParameterTemplate;
use App\Models\Task;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menyimpan parameter sebuah tugas dan mengingat jenisnya sebagai templat yang bisa dipakai lagi.
 * Parameter yang sudah disalin ke checklist tidak ikut berubah (checklist menyimpan salinannya sendiri).
 */
final class TaskParameters
{
    /**
     * Baris parameter dari form (sudah divalidasi). Kesalahan batas / pilihan dikirim ke error bag yang diminta.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public static function rows(array $data, string $bag = 'default'): array
    {
        try {
            return ParameterRules::normalize($data['parameters'] ?? []);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages($e->errors())->errorBag($bag);
        }
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function sync(Task $task, array $rows): void
    {
        DB::transaction(function () use ($task, $rows) {
            $task->parameters()->delete();
            foreach ($rows as $index => $row) {
                $task->parameters()->create([...$row, 'sort' => $index + 1]);
                ParameterTemplate::updateOrCreate(
                    ['name' => $row['name'], 'type' => $row['type']],
                    Arr::only($row, ['unit', 'min_value', 'max_value', 'options', 'normal_option']),
                );
            }
        });
    }

    /** Templat yang sudah pernah dibuat, untuk dipilih di form tugas. @return Collection<int, ParameterTemplate> */
    public static function templates(): Collection
    {
        return ParameterTemplate::orderBy('name')->orderBy('type')->get();
    }
}
