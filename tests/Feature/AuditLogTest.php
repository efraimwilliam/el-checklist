<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Position;
use App\Models\User;

it('records changes automatically and lets only administrators read them', function () {
    loggedIn(userWithRole(Role::Admin));

    $department = Department::factory()->create(['name' => 'Front Office']);
    $position = Position::factory()->for($department)->create(['name' => 'GSA']);
    $position->update(['name' => 'GSA Senior']);

    expect(AuditLog::where('action', 'position.created')->exists())->toBeTrue();
    $row = AuditLog::where('action', 'position.updated')->firstOrFail();
    expect($row->changes['name'])->toBe(['GSA', 'GSA Senior'])->and($row->user_name)->not->toBe('Sistem');
    expect(AuditLog::where('action', 'login.success')->exists())->toBeTrue();

    $this->get(route('admin.logs.index'))->assertOk()->assertSee('position.updated')->assertSee('GSA Senior');
    $this->get(route('admin.logs.index', ['aksi' => 'login.success']))->assertOk()->assertDontSee('text-xs text-slate-700">position.updated</code>', false);

    $this->post('/logout');
    loggedIn(userWithRole(Role::Hr));
    $this->get(route('admin.logs.index'))->assertForbidden();
});

it('shows the log link in Pengaturan to administrators only', function () {
    loggedIn(userWithRole(Role::Admin));
    $this->get(route('admin.settings.edit'))->assertOk()->assertSee('Log aplikasi');
    $this->post('/logout');

    loggedIn(User::factory()->hodOf(Department::factory()->create())->create());
    $this->get(route('admin.settings.edit'))->assertOk()->assertDontSee('Log aplikasi');
});

it('prunes application logs older than the retention period only', function () {
    config(['audit.retention_days' => 30]);
    AuditLog::create(['occurred_at' => now()->subDays(40), 'user_name' => 'Lama', 'action' => 'x.created']);
    AuditLog::create(['occurred_at' => now()->subDays(5), 'user_name' => 'Baru', 'action' => 'x.created']);

    $this->artisan('audit:prune')->assertSuccessful();

    expect(AuditLog::pluck('user_name')->all())->toBe(['Baru']);
});
