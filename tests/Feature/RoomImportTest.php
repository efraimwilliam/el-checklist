<?php

use App\Enums\Role;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function roomWorkbook(array $rows): UploadedFile
{
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray($rows, null, 'A1');
    $path = tempnam(sys_get_temp_dir(), 'kamar').'.xlsx';
    (new Xlsx($book))->save($path);

    return new UploadedFile($path, 'kamar.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

beforeEach(function () {
    Storage::fake('local');
    loggedIn(User::factory()->role(Role::Hr)->create());
});

it('previews and imports rooms, guessing the floor from the number', function () {
    Room::create(['number' => '101', 'floor' => '1', 'sort' => 1]);

    $file = roomWorkbook([['Nomor kamar', 'Lantai', 'Gedung', 'Tipe'], ['101', '1', null, null], [305, null, 'A', 'Deluxe'], [1204, null, null, null]]);
    $this->post(route('admin.rooms.import.preview'), ['berkas' => $file])->assertOk()->assertSee('Hasil pemeriksaan');
    expect(Room::count())->toBe(1);

    $this->post(route('admin.rooms.import.apply'))->assertRedirect(route('admin.rooms.index'));
    expect(Room::count())->toBe(3)->and(Room::where('number', '305')->value('floor'))->toBe('3')
        ->and(Room::where('number', '305')->value('type'))->toBe('Deluxe')->and(Room::where('number', '1204')->value('floor'))->toBe('12');
});

it('reports file errors and refuses to save them', function () {
    $file = roomWorkbook([['Nomor kamar', 'Lantai'], ['101', '1'], ['101', '1'], [null, '2']]);
    $this->post(route('admin.rooms.import.preview'), ['berkas' => $file])->assertOk()->assertSee('sudah ada di baris lain')->assertSee('Nomor kamar kosong');

    $this->post(route('admin.rooms.import.apply'))->assertRedirect(route('admin.rooms.import'));
    expect(Room::count())->toBe(0);

    $this->post(route('admin.rooms.import.preview'), ['berkas' => roomWorkbook([['Lantai'], ['1']])])->assertSessionHasErrors('berkas');
});

it('is closed to staff', function () {
    $this->post('/logout');
    loggedIn(User::factory()->role(Role::Staff)->create());
    $this->get(route('admin.rooms.index'))->assertForbidden();
    $this->get(route('admin.rooms.import'))->assertForbidden();
});
