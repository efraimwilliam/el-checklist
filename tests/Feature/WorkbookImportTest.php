<?php

use App\Models\Department;
use App\Models\Position;
use App\Models\Task;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function makeWorkbook(): string
{
    $book = new Spreadsheet;
    $sheets = [
        'Departemen' => [['Kode', 'Nama', 'Urutan', 'Aktif'], ['FO', 'Front Office', 2, true], ['HK', 'Housekeeping', 3, true]],
        'Jabatan' => [['ID', 'Kode Dept', 'Nama Jabatan', 'Urutan', 'Aktif'], [3, 'FO', 'Front Office Manager (FOM)', 1, true], [12, 'HK', 'Room Attendant (RA)', 3, true]],
        'Tugas' => [
            ['ID', 'ID Jabatan', 'Bagian', 'Tugas', 'Cara / Standar', 'Keterangan', 'Urutan', 'Aktif'],
            [36, 3, '', 'Check FO daily shared report', 'Baca laporan harian FO', '', 1, true],
            [37, 3, '', 'Room control assignment', 'Pastikan room blocking', 'Harian', 2, true],
            [90, 12, 'Kamar', 'Clean the room', null, null, 1, 'FALSE'], // penulis PhpSpreadsheet menghilangkan nilai false
        ],
        'Akun' => [['Username', 'Nama', 'Role', 'Kode Dept', 'PIN', 'Aktif'], ['hr', 'HR Manager', 'hr', 'HR', '0000', true]],
    ];
    $book->removeSheetByIndex(0);
    foreach ($sheets as $name => $rows) {
        $book->createSheet()->setTitle($name)->fromArray($rows);
    }
    $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
    (new Xlsx($book))->save($path);

    return $path;
}

it('imports departments, positions and tasks, can be re-run, and ignores the Akun sheet', function () {
    $synced = Department::create(['code' => 'FO', 'name' => 'Front Office (absensi)', 'external_id' => 5]);
    $path = makeWorkbook();

    $this->artisan('checklist:import-workbook', ['path' => $path])->assertSuccessful();
    $this->artisan('checklist:import-workbook', ['path' => $path])->assertSuccessful();
    unlink($path);

    expect(Department::count())->toBe(2)
        ->and($synced->fresh()->name)->toBe('Front Office (absensi)') // nama absensi dipertahankan
        ->and(Position::count())->toBe(2)
        ->and(Task::count())->toBe(3)
        ->and(Task::where('title', 'Room control assignment')->sole())->remarks->toBe('Harian')->sort->toBe(2)
        ->and(Task::where('title', 'Clean the room')->sole())->is_active->toBeFalse()->section->toBe('Kamar');
    $this->assertDatabaseMissing('departments', ['code' => 'HR']);
});
