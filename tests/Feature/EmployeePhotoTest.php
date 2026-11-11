<?php

use App\Attendance\EmployeePhotoSync;
use App\Checklists\ChecklistSettings;
use App\Enums\Role;
use App\Models\ChatThread;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const ATTENDANCE_PHOTO = 'attendance.test/api/v1/tasks/employees/*/photo';

function photoJpeg(int $width = 800, int $height = 600): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 40));
    ob_start();
    imagejpeg($image);

    return (string) ob_get_clean();
}

function fakePhotoAttendance(array $employees, mixed $photo): void
{
    fakeAttendance([
        'attendance.test/api/v1/tasks/departments' => Http::response(['data' => [['id' => 5, 'code' => 'ENG', 'name' => 'Engineering', 'heads' => []]]]),
        'attendance.test/api/v1/tasks/employees' => Http::response(['data' => $employees]),
        ATTENDANCE_PHOTO => $photo,
    ]);
}

function photoRow(Employee $employee, ?string $version, bool $active = true): array
{
    return ['id' => $employee->external_id, 'username' => $employee->username, 'name' => $employee->name, 'position' => 'Teknisi',
        'department_id' => 5, 'is_active' => $active, 'photo_version' => $version];
}

beforeEach(function () {
    Storage::fake('local');
    $this->dept = Department::factory()->create(['name' => 'Engineering', 'external_id' => 5]);
    $this->ani = User::factory()->role(Role::Staff)->create(['employee_id' => Employee::factory()->for($this->dept)->create(['name' => 'Ani Lestari'])->id]);
    $this->budi = User::factory()->role(Role::Staff)->create(['employee_id' => Employee::factory()->for($this->dept)->create(['name' => 'Budi Santoso'])->id]);
    app(ChecklistSettings::class)->put('chat_enabled', '1');
});

it('copies changed employee photos from attendance during the sync, re-encoded and small', function () {
    $budi = $this->budi->employee;
    fakePhotoAttendance([photoRow($this->ani->employee, null), photoRow($budi, 'abc12345')], Http::response(photoJpeg(), 200, ['Content-Type' => 'image/jpeg']));

    $this->artisan('attendance:sync')->assertSuccessful()->expectsOutputToContain('Foto pegawai: 1 diperbarui');

    expect($budi->fresh())->photo_version->toBe('abc12345')->photo_cached_version->toBe('abc12345')
        ->and($this->ani->employee->fresh()->photo_cached_version)->toBeNull();
    $size = getimagesizefromstring(Storage::disk('local')->get(EmployeePhotoSync::path($budi)));
    expect(max($size[0], $size[1]))->toBe(EmployeePhotoSync::MAX_EDGE)->and($size[2])->toBe(IMAGETYPE_JPEG);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), "/employees/{$budi->external_id}/photo") && $request->hasHeader('Authorization'));

    // Versi sama = tidak diunduh lagi.
    fakePhotoAttendance([photoRow($this->ani->employee, null), photoRow($budi, 'abc12345')], Http::response('tidak boleh dipanggil', 500));
    $this->artisan('attendance:sync')->assertSuccessful()->expectsOutputToContain('Foto pegawai: 0 diperbarui');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/photo'));
});

it('replaces the copy when the version changes and removes it when attendance drops the photo', function () {
    $budi = $this->budi->employee;
    fakePhotoAttendance([photoRow($budi, 'v1')], Http::response(photoJpeg(), 200));
    $this->artisan('attendance:sync')->assertSuccessful();

    fakePhotoAttendance([photoRow($budi, 'v2')], Http::response(photoJpeg(100, 100), 200));
    $this->artisan('attendance:sync')->assertSuccessful();
    expect($budi->fresh()->photo_cached_version)->toBe('v2')
        ->and(getimagesizefromstring(Storage::disk('local')->get(EmployeePhotoSync::path($budi)))[0])->toBe(100);

    fakePhotoAttendance([photoRow($budi, null)], Http::response('', 404));
    $this->artisan('attendance:sync')->assertSuccessful();
    expect($budi->fresh()->photo_cached_version)->toBeNull();
    Storage::disk('local')->assertMissing(EmployeePhotoSync::path($budi));
});

it('keeps the sync working when photos cannot be fetched or are not images', function () {
    $budi = $this->budi->employee;
    fakePhotoAttendance([photoRow($budi, 'v1')], Http::response('rusak', 500));
    $this->artisan('attendance:sync')->assertSuccessful()->expectsOutputToContain('1 gagal');
    expect($budi->fresh())->photo_version->toBe('v1')->photo_cached_version->toBeNull();

    fakePhotoAttendance([photoRow($budi, 'v1')], Http::response('<?php echo 1;', 200));
    $this->artisan('attendance:sync')->assertSuccessful()->expectsOutputToContain('1 gagal');
    expect($budi->fresh()->photo_cached_version)->toBeNull();
});

it('shows the photo in the chat and serves it only to chat users', function () {
    $budi = $this->budi->employee;
    fakePhotoAttendance([photoRow($this->ani->employee, null), photoRow($budi, 'v1')], Http::response(photoJpeg(), 200));
    $this->artisan('attendance:sync')->assertSuccessful();
    $url = route('chat.employee-photo', ['employee' => $budi->id, 'v' => 'v1']);

    loggedIn($this->ani);
    $this->get(route('chat.index'))->assertOk()->assertSee($url, false); // daftar kontak
    $this->post(route('chat.direct', $this->budi))->assertRedirect();
    $this->postJson(route('chat.send', ChatThread::sole()), ['body' => 'Halo'])->assertSuccessful();
    $this->get(route('chat.show', ChatThread::sole()))->assertOk()->assertSee($url, false); // avatar chat pribadi

    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->get(route('chat.employee-photo', ['employee' => $this->ani->employee->id]))->assertNotFound(); // tanpa foto = inisial

    app(ChecklistSettings::class)->put('chat_enabled', '0');
    $this->get($url)->assertForbidden();
});

it('shows group members with their photos and falls back to initials', function () {
    $budi = $this->budi->employee;
    fakePhotoAttendance([photoRow($this->ani->employee, null), photoRow($budi, 'v1')], Http::response(photoJpeg(), 200));
    $this->artisan('attendance:sync')->assertSuccessful();

    loggedIn($this->ani);
    $this->post(route('chat.store'), ['subject' => 'Tim AC', 'users' => [$this->budi->id]])->assertRedirect();
    $this->get(route('chat.show', ChatThread::sole()))->assertOk()
        ->assertSee(route('chat.employee-photo', ['employee' => $budi->id, 'v' => 'v1']), false)
        ->assertSee('>AL</span>', false); // Ani tanpa foto: inisial
});

it('never lets guests fetch employee photos', function () {
    $this->get(route('chat.employee-photo', ['employee' => $this->budi->employee->id]))->assertRedirect(route('login'));
});
