<?php

use App\Enums\Role;
use App\Models\ChronologyReport;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// Chronology Report di aplikasi Android: buat draft, ubah, finalisasi (terkunci), teks salinan sama dengan web.
it('creates, edits and finalizes a chronology report from the app', function () {
    $fo = Department::factory()->create(['name' => 'Front Office']);
    $author = User::factory()->role(Role::Staff)->create(['employee_id' => Employee::factory()->for($fo)->create()->id]);
    Sanctum::actingAs($author);
    $this->getJson('/api/v1/app/me')->assertJsonPath('user.features.chronology', true);

    $payload = ['check_in' => '2026-11-02', 'check_out' => '2026-11-05', 'nationality' => 'Australia', 'booking_source' => 'Agoda',
        'guests' => [['name' => 'Mr. John Smith', 'room_number' => '305']], 'entries' => [['body' => 'Tamu melapor AC tidak dingin.'], ['body' => '']]];
    $this->postJson('/api/v1/app/chronology', ['guests' => [['name' => '']], 'entries' => [['body' => 'x']], 'check_in' => '2026-11-02'])->assertStatus(422)->assertJsonValidationErrors('guests');
    $id = $this->postJson('/api/v1/app/chronology', $payload)->assertOk()->assertJsonPath('message', 'Draft disimpan.')->json('id');

    $this->getJson('/api/v1/app/chronology')->assertJsonPath('reports.0.id', $id)->assertJsonPath('reports.0.room', '305')->assertJsonPath('reports.0.entries', 1);
    $this->putJson("/api/v1/app/chronology/{$id}", ['entries' => [['body' => 'Poin pertama.'], ['body' => 'Poin kedua.']]] + $payload)->assertOk();
    $show = $this->getJson("/api/v1/app/chronology/{$id}")->assertOk()->assertJsonPath('report.can.update', true)->assertJsonCount(2, 'report.entries');
    expect($show->json('report.text'))->toContain('*CHRONOLOGY REPORT*')->toContain('Mr. John Smith');

    $this->postJson("/api/v1/app/chronology/{$id}/final")->assertOk();
    expect(ChronologyReport::find($id)->status)->toBe('final');
    $this->putJson("/api/v1/app/chronology/{$id}", $payload)->assertForbidden();
});
