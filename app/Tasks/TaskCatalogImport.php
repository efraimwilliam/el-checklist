<?php

namespace App\Tasks;

use App\Models\Position;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Daftar tugas lewat Excel. Ekspor berisi baris yang sama dengan tabel Daftar tugas (kolom ID = kunci).
 * Impor dua langkah: berkas diperiksa dulu (pratinjau), dan baru disimpan bila tidak ada kesalahan.
 * Baris tanpa ID = tugas baru di jabatan yang disebut; baris dengan ID = ubah tugas itu. Tugas yang tidak ada di berkas tidak dihapus
 * (hapus tetap lewat Kelola tugas). Pindah tugas antar jabatan tidak didukung.
 */
final class TaskCatalogImport
{
    public const HEADERS = ['ID', 'Departemen', 'Jabatan', 'Jenis', 'Nama tugas', 'Standar'];

    /** Kolom yang wajib ada di baris judul berkas impor. */
    private const REQUIRED = ['Departemen', 'Jabatan', 'Nama tugas'];

    public function __construct(private readonly User $user) {}

    /** @return list<list<mixed>> */
    public function exportRows(): array
    {
        return $this->visibleTasks()->map(fn (Task $task) => [
            $task->id,
            $task->position->department->name,
            $task->position->name,
            $task->section,
            $task->title,
            $task->standard,
        ])->values()->all();
    }

    /**
     * Membaca berkas dan menyusun rencana perubahan. Tidak mengubah apa pun.
     *
     * @return array{create: list<array{position_id: int, fields: array, line: int, title: string, position: string}>, update: list<array{id: int, fields: array, line: int, title: string}>, same: int, errors: list<array{line: int, message: string}>}
     *
     * @throws InvalidTaskFile
     */
    public function plan(string $path): array
    {
        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);
        $header = array_map(fn ($value) => $this->key($value), array_shift($rows) ?? []);

        foreach (self::REQUIRED as $column) {
            if (! in_array($this->key($column), $header, true)) {
                throw new InvalidTaskFile(__('Kolom ":column" tidak ditemukan di baris judul. Unduh ulang berkas dari Kamus daftar tugas.', ['column' => $column]));
            }
        }

        $cell = fn (array $row, string $column) => ($index = array_search($this->key($column), $header, true)) === false ? null : $this->clean($row[$index] ?? null);

        $positions = $this->positions()->keyBy(fn (Position $position) => $this->key($position->department->name).'|'.$this->key($position->name));
        $tasks = $this->visibleTasks()->keyBy('id');

        $plan = ['create' => [], 'update' => [], 'same' => 0, 'errors' => []];
        $seen = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $id = $cell($row, 'ID');
            $department = $cell($row, 'Departemen');
            $position = $cell($row, 'Jabatan');
            $fields = ['section' => $cell($row, 'Jenis'), 'title' => $cell($row, 'Nama tugas'), 'standard' => $cell($row, 'Standar')];

            if ($id === null && $department === null && $position === null && $fields['title'] === null) {
                continue; // baris kosong
            }
            if ($fields['title'] === null) {
                $plan['errors'][] = ['line' => $line, 'message' => __('Nama tugas kosong.')];

                continue;
            }
            if (mb_strlen($fields['title']) > 500 || mb_strlen((string) $fields['section']) > 255 || mb_strlen((string) $fields['standard']) > 2000) {
                $plan['errors'][] = ['line' => $line, 'message' => __('Nama tugas maksimal 500, Jenis 255, dan Standar 2000 karakter.')];

                continue;
            }

            if ($id !== null) {
                if (! is_numeric($id) || ! isset($tasks[(int) $id])) {
                    $plan['errors'][] = ['line' => $line, 'message' => __('ID tugas tidak dikenal atau di luar departemen Anda.')];

                    continue;
                }
                $task = $tasks[(int) $id];
                if (isset($seen[$task->id])) {
                    $plan['errors'][] = ['line' => $line, 'message' => __('ID tugas ini sudah ada di baris lain.')];

                    continue;
                }
                $seen[$task->id] = true;

                if (($department !== null && $this->key($department) !== $this->key($task->position->department->name))
                    || ($position !== null && $this->key($position) !== $this->key($task->position->name))) {
                    $plan['errors'][] = ['line' => $line, 'message' => __('Departemen atau Jabatan tidak sama dengan tugas ini. Pindah antar jabatan tidak didukung; untuk tugas baru kosongkan kolom ID.')];

                    continue;
                }

                $changed = collect($fields)->contains(fn ($value, $key) => $value !== $task->{$key});
                if ($changed) {
                    $plan['update'][] = ['id' => $task->id, 'fields' => $fields, 'line' => $line, 'title' => $fields['title']];
                } else {
                    $plan['same']++;
                }

                continue;
            }

            if ($department === null || $position === null) {
                $plan['errors'][] = ['line' => $line, 'message' => __('Departemen dan Jabatan wajib diisi untuk tugas baru.')];

                continue;
            }
            $target = $positions->get($this->key($department).'|'.$this->key($position));
            if (! $target) {
                $plan['errors'][] = ['line' => $line, 'message' => __('Jabatan ":position" di ":department" tidak ditemukan atau di luar departemen Anda.', ['position' => $position, 'department' => $department])];

                continue;
            }
            $plan['create'][] = ['position_id' => $target->id, 'fields' => $fields, 'line' => $line, 'title' => $fields['title'], 'position' => $target->name];
        }

        return $plan;
    }

    /**
     * Menyimpan rencana yang sudah diperiksa. Berkas dibaca ulang dan diperiksa lagi, jadi hasilnya tetap sesuai izin saat ini.
     *
     * @return array{created: int, updated: int}
     *
     * @throws InvalidTaskFile
     */
    public function apply(string $path): array
    {
        $plan = $this->plan($path);
        if ($plan['errors']) {
            throw new InvalidTaskFile(__('Berkas masih punya kesalahan. Perbaiki dulu, lalu periksa ulang.'));
        }

        DB::transaction(function () use ($plan) {
            foreach ($plan['update'] as $row) {
                Task::query()->whereKey($row['id'])->firstOrFail()->update($row['fields']);
            }
            foreach (collect($plan['create'])->groupBy('position_id') as $positionId => $rows) {
                $position = Position::findOrFail($positionId);
                $sort = (int) $position->tasks()->max('sort');
                foreach ($rows as $row) {
                    $position->tasks()->create($row['fields'] + ['sort' => ++$sort]);
                }
            }
        });

        return ['created' => count($plan['create']), 'updated' => count($plan['update'])];
    }

    /** Tugas aktif yang boleh dilihat pengguna ini (sama dengan tabel Daftar tugas). @return Collection<int, Task> */
    private function visibleTasks(): Collection
    {
        $allowed = $this->user->accessibleDepartmentIds();

        return Task::query()->select('tasks.*')
            ->join('positions', 'positions.id', '=', 'tasks.position_id')
            ->join('departments', 'departments.id', '=', 'positions.department_id')
            ->where('tasks.is_active', true)->where('positions.is_active', true)->where('departments.is_active', true)
            ->when($allowed !== null, fn ($q) => $q->whereIn('positions.department_id', $allowed))
            ->with('position.department')
            ->orderBy('departments.name')->orderBy('positions.name')->orderBy('tasks.sort')->orderBy('tasks.id')
            ->get();
    }

    /** Jabatan aktif di departemen yang boleh diubah pengguna ini. @return Collection<int, Position> */
    private function positions(): Collection
    {
        $allowed = $this->user->accessibleDepartmentIds();

        return Position::query()->where('is_active', true)
            ->whereHas('department', fn ($q) => $q->where('is_active', true)->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed)))
            ->with('department')->get();
    }

    private function key(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
