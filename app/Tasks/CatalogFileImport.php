<?php

namespace App\Tasks;

use App\Models\Department;
use App\Models\Position;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * Impor katalog jabatan, tugas, dan parameter dari berkas JSON (database/data/katalog-awal.json).
 * Departemen dikenali lewat kode. Aman diulang: yang sudah ada (jabatan: nama; tugas: bagian + judul) tidak diubah dan tidak dihapus.
 * Tanpa $apply semuanya dikerjakan lalu dibatalkan, jadi angka simulasi sama dengan hasil sebenarnya.
 */
class CatalogFileImport
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{rows: list<array{code: string, name: string, positions_new: int, positions_existing: int, tasks_new: int, tasks_existing: int}>, missing: list<string>, parameters_new: int}
     */
    public function run(array $data, bool $apply): array
    {
        $rows = [];
        $missing = [];
        $parameters = 0;

        DB::beginTransaction();

        try {
            foreach ($data['departemen'] ?? [] as $entry) {
                $department = Department::where('code', $entry['kode'])->first();
                if (! $department) {
                    $missing[] = $entry['kode'].' ('.($entry['nama'] ?? '').')';

                    continue;
                }

                $row = ['code' => $entry['kode'], 'name' => $department->name, 'positions_new' => 0, 'positions_existing' => 0, 'tasks_new' => 0, 'tasks_existing' => 0];

                foreach ($entry['jabatan'] as $item) {
                    $position = Position::where('department_id', $department->id)->where('name', $item['nama'])->first();
                    $position ? $row['positions_existing']++ : $row['positions_new']++;
                    $position ??= Position::create(['department_id' => $department->id, 'name' => $item['nama'], 'sort' => $item['urutan'] ?? 0, 'is_active' => $item['aktif'] ?? true]);

                    $known = $position->tasks()->get()->mapWithKeys(fn (Task $task) => [$this->key($task->section, $task->title) => true]);

                    foreach ($item['tugas'] as $row_task) {
                        $key = $this->key($row_task['bagian'] ?? null, $row_task['judul']);
                        if ($known->has($key)) {
                            $row['tasks_existing']++;

                            continue;
                        }

                        $task = Task::create([
                            'position_id' => $position->id, 'section' => $row_task['bagian'] ?? null, 'title' => $row_task['judul'], 'standard' => $row_task['standar'] ?? null,
                            'remarks' => $row_task['catatan'] ?? null, 'sort' => $row_task['urutan'] ?? 0, 'is_active' => $row_task['aktif'] ?? true,
                        ]);
                        $row['tasks_new']++;

                        foreach ($row_task['parameter'] ?: [['urutan' => 1, 'nama' => 'Status', 'jenis' => 'status']] as $parameter) {
                            $task->parameters()->create([
                                'sort' => $parameter['urutan'] ?? 1, 'name' => $parameter['nama'], 'type' => $parameter['jenis'], 'unit' => $parameter['satuan'] ?? null,
                                'min_value' => $parameter['min'] ?? null, 'max_value' => $parameter['maks'] ?? null, 'options' => $parameter['pilihan'] ?? null, 'normal_option' => $parameter['normal'] ?? null,
                            ]);
                            $parameters++;
                        }
                    }
                }

                $rows[] = $row;
            }

            $apply ? DB::commit() : DB::rollBack();
        } catch (\Throwable $error) {
            DB::rollBack();

            throw $error;
        }

        return ['rows' => $rows, 'missing' => $missing, 'parameters_new' => $parameters];
    }

    private function key(?string $section, string $title): string
    {
        return mb_strtolower(trim((string) $section)).'|'.mb_strtolower(trim($title));
    }
}
