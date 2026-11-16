<?php

use App\Models\Employee;
use App\Models\User;
use App\Push\AppPushSender;

// `aplikasi:cek-notif`: menunjukkan kenapa notifikasi tidak masuk dan bisa mengirim notifikasi uji.
it('explains the device state and sends a test notification', function () {
    $fake = new FakeAppPushSender;
    app()->instance(AppPushSender::class, $fake);
    config(['services.fcm.credentials' => '/tidak/ada.json']);
    $user = User::factory()->create(['employee_id' => Employee::factory()->create(['username' => 'budi'])->id]);

    $this->artisan('aplikasi:cek-notif', ['username' => 'budi'])->expectsOutputToContain('Berkas Firebase tidak ada')
        ->expectsOutputToContain('Belum ada HP yang login')->assertFailed();

    $user->createToken('Pixel')->accessToken->forceFill(['fcm_token' => 'hp-budi'])->save();
    $old = $user->createToken('HP lama')->accessToken;
    $old->forceFill(['fcm_token' => 'hp-lama', 'created_at' => now()->subDays(2)])->save();

    $this->artisan('aplikasi:cek-notif', ['username' => 'budi', '--kirim' => true])
        ->expectsOutputToContain('siap menerima notifikasi')->expectsOutputToContain('KEDALUWARSA')
        ->expectsOutputToContain('diterima Firebase')->assertSuccessful();
    expect(collect($fake->sent)->pluck('token')->all())->toBe(['hp-budi']);
});
