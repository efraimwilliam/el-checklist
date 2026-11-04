<?php

use App\Attendance\AttendanceApiService;
use App\Attendance\AuthOutcome;
use Illuminate\Support\Facades\Http;

it('treats a 422 from Absensi as a wrong credential, not as the service being down', function () {
    // PIN lebih dari 6 karakter ditolak Absensi dengan 422; dulu ini tampil sebagai "sistem absensi tidak dapat dihubungi".
    fakeAttendance([ATTENDANCE_LOGIN => Http::response(['message' => 'The pin field must not be greater than 6 characters.'], 422)]);

    expect(app(AttendanceApiService::class)->authenticate('william', 'rahasia-panjang')->outcome)
        ->toBe(AuthOutcome::InvalidCredentials);
});

it('still reports a real server error as unavailable', function () {
    fakeAttendance([ATTENDANCE_LOGIN => Http::response('error', 500)]);

    expect(app(AttendanceApiService::class)->authenticate('william', '482913')->outcome)
        ->toBe(AuthOutcome::Unavailable);
});
