<?php

namespace App\Checklists;

use App\Models\Department;
use App\Models\Position;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Impor data master dari workbook checklist lama (sheet Departemen, Jabatan, Tugas). Bisa diulang: data yang sama diperbarui.
 * Sheet Akun (berisi PIN) sengaja TIDAK dibaca: login hanya lewat Sistem Absensi.
 */
class WorkbookImporter
{
    /** @return array{departments: int, positions: int, tasks: int} */
    public function import(string $path): array
    {
        $book = IOFactory::createReaderForFile($path)->setReadDataOnly(true)->setLoadSheetsOnly(['Departemen', 'Jabatan', 'Tugas'])->load($path);
        foreach (['Departemen', 'Jabatan', 'Tugas'] as $sheet) {
            throw_unless($book->getSheetByName($sheet), RuntimeException::class, "Sheet \"{$sheet}\" tidak ada di workbook.");
        }

        return DB::transaction(function () use ($book) {
            $departments = [];
            foreach ($this->rows($book->getSheetByName('Departemen')) as $row) {
                $code = strtoupper(trim((string) $row['Kode']));
                if ($code === '') {
                    continue;
                }
                // Departemen yang sudah tersinkron dari absensi tetap memakai nama absensi.
                $department = Department::firstOrNew(['code' => $code]);
                if (! $department->external_id) {
                    $department->name = trim((string) $row['Nama']);
                }
                $department->sort = (int) $row['Urutan'];
                $department->save();
                $departments[$code] = $department->id;
            }

            $positions = [];
            foreach ($this->rows($book->getSheetByName('Jabatan')) as $row) {
                $departmentId = $departments[strtoupper(trim((string) $row['Kode Dept']))] ?? null;
                $name = trim((string) $row['Nama Jabatan']);
                if (! $departmentId || $name === '') {
                    continue;
                }
                $position = Position::updateOrCreate(
                    ['department_id' => $departmentId, 'name' => $name],
                    ['sort' => (int) $row['Urutan'], 'is_active' => $this->bool($row['Aktif'])],
                );
                $positions[(int) $row['ID']] = $position->id;
            }

            $tasks = 0;
            foreach ($this->rows($book->getSheetByName('Tugas')) as $row) {
                $positionId = $positions[(int) $row['ID Jabatan']] ?? null;
                $title = trim((string) $row['Tugas']);
                if (! $positionId || $title === '') {
                    continue;
                }
                Task::updateOrCreate(['position_id' => $positionId, 'title' => $title], [
                    'section' => $this->text($row['Bagian']),
                    'standard' => $this->text($row['Cara / Standar']),
                    'remarks' => $this->text($row['Keterangan']),
                    'sort' => (int) $row['Urutan'],
                    'is_active' => $this->bool($row['Aktif']),
                ]);
                $tasks++;
            }

            return ['departments' => count($departments), 'positions' => count($positions), 'tasks' => $tasks];
        });
    }

    /** @return iterable<array<string, mixed>> baris berisi, dengan kunci = judul kolom */
    private function rows(Worksheet $sheet): iterable
    {
        $rows = $sheet->toArray(null, true, false, false);
        $header = array_map(fn ($h) => trim((string) $h), array_shift($rows) ?? []);
        foreach ($rows as $row) {
            if (array_filter($row, fn ($v) => $v !== null && $v !== '') === []) {
                continue;
            }
            yield array_combine($header, array_pad($row, count($header), null)) + array_fill_keys($header, null);
        }
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function bool(mixed $value): bool
    {
        return $value === null || $value === '' || filter_var($value, FILTER_VALIDATE_BOOL) || $value === 1 || $value === 1.0;
    }
}
