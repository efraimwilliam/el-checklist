<?php

use App\Enums\Role;
use App\Models\Department;
use App\Models\Position;
use App\Models\Task;
use App\Tasks\TaskCatalogImport;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Berkas .xlsx dengan baris judul standar dan baris isi $rows. */
function taskWorkbook(array $rows): string
{
    $book = new Spreadsheet();
    $book->getActiveSheet()->fromArray([TaskCatalogImport::HEADERS, ...$rows], null, 'A1');
    ob_start();
    (new Xlsx($book))->save('php://output');

    return ob_get_clean();
}

function uploadTasks(string $bytes)
{
    return test()->post(route('team.catalog.import.preview'), ['berkas' => UploadedFile::fake()->createWithContent('daftar.xlsx', $bytes)]);
}

beforeEach(function () {
    $this->fo = Department::factory()->create(['name' => 'Front Office', 'is_active' => true]);
    $this->hk = Department::factory()->create(['name' => 'Housekeeping', 'is_active' => true]);
    $this->gsa = Position::factory()->create(['department_id' => $this->fo->id, 'name' => 'GSA', 'is_active' => true]);
    $this->hkPos = Position::factory()->create(['department_id' => $this->hk->id, 'name' => 'Room Attendant', 'is_active' => true]);
    $this->sapaan = Task::factory()->create(['position_id' => $this->gsa->id, 'title' => 'Sapa tamu', 'standard' => 'Senyum', 'section' => 'Lobi', 'sort' => 1, 'is_active' => true]);
});

it('exports the task list to Excel with the ID column', function () {
    loggedIn(userWithRole(Role::Hr));

    $response = $this->get(route('team.catalog.export'))->assertOk();
    $path = storage_path('framework/testing/daftar-tugas.xlsx');
    file_put_contents($path, $response->streamedContent());
    $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);
    unlink($path);

    expect($rows[0])->toBe(TaskCatalogImport::HEADERS)
        ->and($rows[1])->toBe([(string) $this->sapaan->id, 'Front Office', 'GSA', 'Lobi', 'Sapa tamu', 'Senyum']);
});

it('previews the changes, then saves them only when confirmed', function () {
    loggedIn(userWithRole(Role::Hr));
    $bytes = taskWorkbook([
        [$this->sapaan->id, 'Front Office', 'GSA', 'Lobi', 'Sapa tamu dengan senyum', 'Senyum'],
        [null, 'Front Office', 'GSA', 'Lobi', 'Antar koper', 'Dalam 5 menit'],
    ]);

    uploadTasks($bytes)->assertRedirect(route('team.catalog.import'));
    $this->get(route('team.catalog.import'))->assertOk()->assertSee('Tugas baru: 1')->assertSee('Diubah: 1')->assertSee('Antar koper');
    expect(Task::where('title', 'Antar koper')->exists())->toBeFalse();

    $this->post(route('team.catalog.import.apply'))->assertRedirect(route('team.catalog'));

    expect($this->sapaan->fresh()->title)->toBe('Sapa tamu dengan senyum')
        ->and(Task::where('title', 'Antar koper')->where('position_id', $this->gsa->id)->exists())->toBeTrue();
});

it('shows errors and refuses to save while the file has any', function () {
    loggedIn(userWithRole(Role::Hr));
    uploadTasks(taskWorkbook([
        [$this->sapaan->id, 'Front Office', 'GSA', 'Lobi', 'Diubah', 'Senyum'],
        [999999, 'Front Office', 'GSA', 'Lobi', 'ID salah', 'Senyum'],
        [null, 'Front Office', 'Jabatan tidak ada', 'Lobi', 'Tugas baru', 'Senyum'],
    ]));

    $this->get(route('team.catalog.import'))->assertOk()
        ->assertSee('Baris 3: ID tugas tidak dikenal atau di luar departemen Anda.')
        ->assertSee('Baris 4: Jabatan "Jabatan tidak ada" di "Front Office" tidak ditemukan atau di luar departemen Anda.');

    $this->post(route('team.catalog.import.apply'))->assertRedirect(route('team.catalog.import'))->assertSessionHasErrors('berkas');
    expect($this->sapaan->fresh()->title)->toBe('Sapa tamu');
});

it('limits a HOD to positions in their own department', function () {
    $hod = userWithRole(Role::Hod);
    $hod->departments()->attach($this->fo);
    loggedIn($hod);

    uploadTasks(taskWorkbook([[null, 'Housekeeping', 'Room Attendant', 'Kamar', 'Rapikan tempat tidur', null]]));

    $this->get(route('team.catalog.import'))->assertSee('di luar departemen Anda');
    $this->post(route('team.catalog.import.apply'))->assertSessionHasErrors('berkas');
    expect(Task::where('title', 'Rapikan tempat tidur')->exists())->toBeFalse();
});

it('refuses a file without the expected columns and keeps staff out', function () {
    loggedIn(userWithRole(Role::Hr));
    $book = new Spreadsheet();
    $book->getActiveSheet()->fromArray([['Nama', 'Catatan'], ['x', 'y']], null, 'A1');
    ob_start();
    (new Xlsx($book))->save('php://output');
    uploadTasks(ob_get_clean());
    $this->get(route('team.catalog.import'))->assertSee('tidak ditemukan di baris judul');

    $this->post('/logout');
    loggedIn(userWithRole(Role::Staff));
    $this->get(route('team.catalog.export'))->assertForbidden();
});
