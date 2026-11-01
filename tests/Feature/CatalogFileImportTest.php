<?php

use App\Models\Department;
use App\Models\Position;
use App\Models\Task;

function catalogFile(array $departments): string
{
    $path = tempnam(sys_get_temp_dir(), 'katalog').'.json';
    file_put_contents($path, json_encode(['versi' => 1, 'departemen' => $departments]));

    return $path;
}

function catalogEntry(string $code = 'FO'): array
{
    return ['kode' => $code, 'nama' => 'Front Office', 'jabatan' => [[
        'nama' => 'Guest Service Agent (GSA)', 'urutan' => 1, 'aktif' => true,
        'tugas' => [
            ['bagian' => 'Pagi', 'judul' => 'Cek e-mail', 'standar' => 'Sebelum jam 8', 'catatan' => null, 'urutan' => 1, 'aktif' => true, 'parameter' => [['urutan' => 1, 'nama' => 'Status', 'jenis' => 'status']]],
            ['bagian' => 'Pagi', 'judul' => 'Genset', 'standar' => null, 'catatan' => null, 'urutan' => 2, 'aktif' => true, 'parameter' => [
                ['urutan' => 1, 'nama' => 'Running Hours', 'jenis' => 'number', 'satuan' => 'jam', 'min' => '10.00', 'maks' => '100.00'],
                ['urutan' => 2, 'nama' => 'Oil Level', 'jenis' => 'option', 'pilihan' => 'Full, Low', 'normal' => 'Full'],
            ]],
        ],
    ]]];
}

it('only simulates by default and saves nothing', function () {
    Department::create(['code' => 'FO', 'name' => 'Front Office']);

    $this->artisan('checklist:katalog-impor', ['file' => catalogFile([catalogEntry()])])
        ->expectsOutputToContain('SIMULASI (belum disimpan): 1 jabatan baru, 2 tugas baru, 3 parameter baru')->assertSuccessful();

    expect(Position::count())->toBe(0)->and(Task::count())->toBe(0);
});

it('creates positions, tasks, and parameters when applied', function () {
    $department = Department::create(['code' => 'FO', 'name' => 'Front Office']);

    $this->artisan('checklist:katalog-impor', ['file' => catalogFile([catalogEntry()]), '--terapkan' => true])->expectsOutputToContain('Tersimpan')->assertSuccessful();

    $position = Position::where('department_id', $department->id)->sole();
    expect($position->name)->toBe('Guest Service Agent (GSA)')->and($position->tasks)->toHaveCount(2);
    $genset = $position->tasks->firstWhere('title', 'Genset');
    expect($genset->parameters->pluck('type')->all())->toBe(['number', 'option'])->and($genset->parameters[1]->normal_option)->toBe('Full');
    expect($position->tasks->firstWhere('title', 'Cek e-mail')->parameters->pluck('type')->all())->toBe(['status']);
});

it('can be repeated without duplicating or overwriting anything', function () {
    $department = Department::create(['code' => 'FO', 'name' => 'Front Office']);
    $file = catalogFile([catalogEntry()]);
    $this->artisan('checklist:katalog-impor', ['file' => $file, '--terapkan' => true])->assertSuccessful();
    Task::where('title', 'Cek e-mail')->update(['standard' => 'Diubah HOD']);

    $this->artisan('checklist:katalog-impor', ['file' => $file, '--terapkan' => true])->expectsOutputToContain('0 jabatan baru, 0 tugas baru, 0 parameter baru')->assertSuccessful();

    expect(Position::where('department_id', $department->id)->count())->toBe(1)->and(Task::count())->toBe(2)
        ->and(Task::where('title', 'Cek e-mail')->value('standard'))->toBe('Diubah HOD');
});

it('adds missing tasks to an existing position and skips unknown departments with a warning', function () {
    $department = Department::create(['code' => 'FO', 'name' => 'Front Office']);
    $position = Position::create(['department_id' => $department->id, 'name' => 'Guest Service Agent (GSA)', 'sort' => 1]);
    Task::create(['position_id' => $position->id, 'section' => 'Pagi', 'title' => 'Cek e-mail', 'sort' => 1]);

    $this->artisan('checklist:katalog-impor', ['file' => catalogFile([catalogEntry(), catalogEntry('XX')]), '--terapkan' => true])
        ->expectsOutputToContain('Departemen tidak ditemukan di database (dilewati): XX')
        ->expectsOutputToContain('0 jabatan baru, 1 tugas baru')->assertSuccessful();

    expect($position->fresh()->tasks)->toHaveCount(2);
});

it('refuses a file that is not a catalog', function () {
    $path = tempnam(sys_get_temp_dir(), 'katalog').'.json';
    file_put_contents($path, '{"lain": true}');

    $this->artisan('checklist:katalog-impor', ['file' => $path])->assertFailed();
    $this->artisan('checklist:katalog-impor', ['file' => '/tidak/ada.json'])->assertFailed();
});

it('imports the shipped catalog into the department codes used on the server', function () {
    foreach (['ACC' => 'Accounting', 'A&G' => 'Admin & General', 'ENG' => 'Engineering', 'FBK' => 'F&B Kitchen', 'FBS' => 'F&B Service', 'FO' => 'Front Office',
        'HK' => 'Housekeeping', 'HR' => 'Human Resources', 'PRJ' => 'Project', 'PA' => 'Public Area', 'S&M' => 'Sales & Marketing', 'SPA' => 'SPA'] as $code => $name) {
        Department::create(['code' => $code, 'name' => $name]);
    }

    $this->artisan('checklist:katalog-impor', ['--terapkan' => true])
        ->expectsOutputToContain('Tersimpan: 50 jabatan baru, 761 tugas baru, 762 parameter baru.')->assertSuccessful();

    expect(Position::where('department_id', Department::where('code', 'PA')->value('id'))->pluck('name')->all())
        ->toBe(['Public Area – Lobby', 'Public Area – Zona 2', 'Public Area – Zona 3'])
        ->and(Position::where('department_id', Department::where('code', 'HK')->value('id'))->count())->toBe(7)
        ->and(Position::where('name', 'like', 'Chief Security%')->exists())->toBeFalse();
});
