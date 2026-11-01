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
    $book = new Spreadsheet;
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
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray([['Nama', 'Catatan'], ['x', 'y']], null, 'A1');
    ob_start();
    (new Xlsx($book))->save('php://output');
    uploadTasks(ob_get_clean());
    $this->get(route('team.catalog.import'))->assertSee('tidak ditemukan di baris judul');

    $this->post('/logout');
    loggedIn(userWithRole(Role::Staff));
    $this->get(route('team.catalog.export'))->assertForbidden();
});

it('opens the import as a popup on the catalog page, with the page link kept as a fallback', function () {
    loggedIn(userWithRole(Role::Hr));

    $html = $this->get(route('team.catalog'))->assertOk()->assertSee('id="import-dialog"', false)->assertSee('data-import-body', false)->getContent();

    expect($html)->toMatch('/<a href="[^"]*\/tim\/daftar\/impor"[^>]*data-import-open>/');
});

it('runs the whole import inside the popup with JSON answers', function () {
    loggedIn(userWithRole(Role::Hr));
    $json = ['Accept' => 'application/json'];

    // Buka: panel kosong berisi formulir unggah.
    $this->getJson(route('team.catalog.import'))->assertOk()->assertJsonPath('html', fn ($html) => str_contains($html, 'name="berkas"') && str_contains($html, 'data-import-form'));

    // Periksa berkas: pratinjau muncul di panel, belum ada yang disimpan.
    $bytes = taskWorkbook([
        [$this->sapaan->id, 'Front Office', 'GSA', 'Lobi', 'Sapa tamu dengan senyum', 'Senyum'],
        [null, 'Front Office', 'GSA', 'Lobi', 'Antar koper', 'Dalam 5 menit'],
    ]);
    $this->post(route('team.catalog.import.preview'), ['berkas' => UploadedFile::fake()->createWithContent('daftar.xlsx', $bytes)], $json)
        ->assertOk()->assertJsonPath('html', fn ($html) => str_contains($html, 'Tugas baru: 1') && str_contains($html, 'Antar koper') && str_contains($html, 'Simpan perubahan'));
    expect(Task::where('title', 'Antar koper')->exists())->toBeFalse();

    // Membuka lagi popup menampilkan pratinjau yang sama (berkas masih menunggu).
    $this->getJson(route('team.catalog.import'))->assertJsonPath('html', fn ($html) => str_contains($html, 'Antar koper'));

    // Simpan: JSON berisi alamat tujuan, dan pesan sukses menunggu di halaman Kamus.
    $this->postJson(route('team.catalog.import.apply'))->assertOk()->assertJson(['redirect' => route('team.catalog')]);
    expect(Task::where('title', 'Antar koper')->where('position_id', $this->gsa->id)->exists())->toBeTrue();
    $this->get(route('team.catalog'))->assertSee('1 tugas ditambahkan, 1 tugas diperbarui dari Excel.');
});

it('reports popup problems in the panel with status 422 and lets the user cancel', function () {
    loggedIn(userWithRole(Role::Hr));

    // Berkas bukan Excel.
    $this->post(route('team.catalog.import.preview'), ['berkas' => UploadedFile::fake()->createWithContent('catatan.txt', 'bukan excel')], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('html', fn ($html) => str_contains($html, 'Berkas harus berformat Excel (.xlsx).') && ! str_contains($html, 'validation.'));

    // Tanpa berkas, dan pesan yang sama di halaman biasa (tanpa JSON).
    $this->post(route('team.catalog.import.preview'), [], ['Accept' => 'application/json'])->assertStatus(422)
        ->assertJsonPath('html', fn ($html) => str_contains($html, 'Pilih berkas Excel (.xlsx) dulu.'));
    $this->from(route('team.catalog.import'))->post(route('team.catalog.import.preview'), ['berkas' => UploadedFile::fake()->createWithContent('catatan.txt', 'x')])
        ->assertRedirect(route('team.catalog.import'))->assertSessionHasErrors(['berkas' => 'Berkas harus berformat Excel (.xlsx).']);

    // Simpan tanpa berkas.
    $this->postJson(route('team.catalog.import.apply'))->assertStatus(422)->assertJsonPath('html', fn ($html) => str_contains($html, 'Unggah berkas dulu sebelum menyimpan.'));

    // Batalkan membuang berkas yang menunggu.
    uploadTasks(taskWorkbook([[null, 'Front Office', 'GSA', 'Lobi', 'Antar koper', 'x']]));
    $this->postJson(route('team.catalog.import.cancel'))->assertOk()->assertJsonPath('html', fn ($html) => ! str_contains($html, 'Antar koper'));
    $this->postJson(route('team.catalog.import.apply'))->assertStatus(422);
});

it('keeps the import steps closed to people who cannot manage team tasks, in JSON too', function () {
    loggedIn(userWithRole(Role::Staff));

    $this->get(route('team.catalog'))->assertForbidden();
    $this->getJson(route('team.catalog.import'))->assertForbidden();
    $this->postJson(route('team.catalog.import.apply'))->assertForbidden();
    $this->postJson(route('team.catalog.import.cancel'))->assertForbidden();
});
