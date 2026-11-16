<?php

use Illuminate\Support\Facades\Storage;

// Pembaruan aplikasi Android dari server sendiri: `aplikasi:rilis` menerbitkan APK, aplikasi mengecek /versi lalu mengunduh.
function fakeApk(string $dir, int $code = 7, string $name = '1.6.0'): string
{
    @mkdir($dir, 0777, true);
    file_put_contents("$dir/app-release.apk", "PK\x03\x04isi apk uji");
    file_put_contents("$dir/output-metadata.json", json_encode(['elements' => [['versionCode' => $code, 'versionName' => $name]]]));

    return "$dir/app-release.apk";
}

it('publishes an APK with its version from the Gradle metadata and serves it without login', function () {
    Storage::fake('local');
    $this->getJson('/api/v1/app/versi')->assertOk()->assertExactJson(['version_code' => 0]);
    $this->get('/aplikasi/eL-Checklist.apk')->assertNotFound();

    $apk = fakeApk(sys_get_temp_dir().'/rilis-uji-'.uniqid());
    $this->artisan('aplikasi:rilis', ['apk' => $apk, '--catatan' => 'Menu Obrolan'])->assertSuccessful();

    $this->getJson('/api/v1/app/versi')->assertOk()->assertJson([
        'version_code' => 7, 'version_name' => '1.6.0', 'min_version_code' => 0, 'notes' => 'Menu Obrolan',
        'sha256' => hash_file('sha256', $apk), 'size' => filesize($apk), 'path' => '/aplikasi/eL-Checklist.apk',
    ]);
    $download = $this->get('/aplikasi/eL-Checklist.apk')->assertOk()->assertHeader('Content-Type', 'application/vnd.android.package-archive');
    expect($download->streamedContent())->toBe(file_get_contents($apk));

    // Versi wajib: yang lebih lama dari 7 harus memperbarui; batas wajib tidak pernah melebihi versi terbit.
    $this->artisan('aplikasi:rilis', ['apk' => fakeApk(sys_get_temp_dir().'/rilis-uji-'.uniqid(), 8, '1.7.0'), '--wajib-sejak' => 99])->assertSuccessful();
    $this->getJson('/api/v1/app/versi')->assertJson(['version_code' => 8, 'min_version_code' => 8]);
});

it('refuses files that are not APKs or have no version', function () {
    Storage::fake('local');
    $bad = tempnam(sys_get_temp_dir(), 'apk');
    file_put_contents($bad, 'bukan apk');
    $this->artisan('aplikasi:rilis', ['apk' => $bad])->assertFailed();

    $dir = sys_get_temp_dir().'/rilis-uji-'.uniqid();
    mkdir($dir);
    file_put_contents("$dir/x.apk", 'PK..');
    $this->artisan('aplikasi:rilis', ['apk' => "$dir/x.apk"])->assertFailed();
    $this->artisan('aplikasi:rilis', ['apk' => "$dir/x.apk", '--versi-kode' => 3, '--versi' => '1.0.3'])->assertSuccessful();
});
