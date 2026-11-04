<?php

use App\Enums\Role;
use App\Models\ChronologyReport;

it('shows the chronology insight on the dashboard for the reports created in the period', function () {
    $user = userWithRole(Role::Hr);
    loggedIn($user);
    $report = ChronologyReport::create([
        'author_id' => $user->id, 'nationality' => 'Australia', 'check_in' => today()->toDateString(),
        'booking_source' => 'Alaric', 'status' => ChronologyReport::STATUS_FINAL, 'finalized_at' => now(),
    ]);
    $report->guests()->create(['sort' => 1, 'name' => 'Ida Gervasi', 'room_number' => '1116']);
    $report->entries()->create(['sort' => 1, 'body' => 'Tamu meminta kamar lain.']);

    $this->get(route('dashboard'))->assertOk()
        ->assertSee('Chronology Report')->assertSee('Sumber booking')->assertSee('Alaric')
        ->assertSee('Kewarganegaraan')->assertSee('Australia')->assertSee('Ida Gervasi')->assertSee('1116');
});
