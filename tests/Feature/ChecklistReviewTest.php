<?php

use App\Enums\ChecklistStatus;
use App\Enums\Role;
use App\Models\Checklist;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->hk = Department::factory()->create(['name' => 'Housekeeping']);
    $this->gsa = Position::factory()->for($this->fo)->create(['name' => 'GSA']);
    Task::factory()->for($this->gsa)->count(2)->create();
    $this->ra = Position::factory()->for($this->hk)->create(['name' => 'Room Attendant']);
    Task::factory()->for($this->ra)->create();

    $this->budi = Employee::factory()->for($this->fo)->create(['name' => 'Budi Santoso']);
    $this->sari = Employee::factory()->for($this->hk)->create(['name' => 'Sari Dewi']);
    $this->budiList = makeChecklist($this->budi, $this->gsa);
    $this->sariList = makeChecklist($this->sari, $this->ra);
    $this->hod = User::factory()->hodOf($this->fo)->create();
});

it('lets the GM, HR and administrators mark a checklist as reviewed, recording who and when', function (Role $role) {
    $reviewer = userWithRole($role);
    loggedIn($reviewer);

    $this->get(route('checklists.show', $this->budiList))->assertOk()->assertSee('Tandai sudah direview');
    $this->post(route('checklists.review', $this->budiList))->assertRedirect(route('checklists.show', $this->budiList));

    expect($this->budiList->fresh())->status->toBe(ChecklistStatus::Reviewed)->reviewed_by->toBe($reviewer->id)->reviewed_at->not->toBeNull();
    $this->get(route('checklists.show', $this->budiList))->assertSee('Sudah direview')->assertSee($reviewer->name())->assertDontSee('Tandai sudah direview');
    $this->post(route('checklists.review', $this->budiList))->assertForbidden(); // sudah direview
})->with([Role::Gm, Role::Hr, Role::Admin]);

it('does not let HODs or staff review, nor anyone review their own checklist', function () {
    loggedIn($this->hod);
    $this->post(route('checklists.review', $this->budiList))->assertForbidden();
    $this->post('/logout');

    $own = User::factory()->for($this->budi)->role(Role::Hr)->create();
    loggedIn($own);
    $this->get(route('checklists.show', $this->budiList))->assertOk()->assertDontSee('Tandai sudah direview');
    $this->post(route('checklists.review', $this->budiList))->assertForbidden();

    expect($this->budiList->fresh()->status)->toBe(ChecklistStatus::Saved);
});

it('locks a reviewed checklist for the HOD but not for HR or administrators', function () {
    $this->budiList->update(['status' => ChecklistStatus::Reviewed, 'reviewed_by' => userWithRole(Role::Gm)->id, 'reviewed_at' => now()]);
    $items = $this->budiList->items->pluck('id');
    $edit = fn () => $this->put(route('checklists.update', $this->budiList), ['items' => answers($items, 'pending')]);

    loggedIn($this->hod);
    $this->get(route('checklists.show', $this->budiList))->assertOk()->assertDontSee('>Edit<', false);
    $this->get(route('fill.create', $this->budiList->fillParams()))->assertSee('terkunci')->assertDontSee('Simpan checklist');
    $edit()->assertForbidden();
    $this->post('/logout');

    loggedIn(userWithRole(Role::Gm));
    $edit()->assertForbidden();
    $this->post('/logout');

    loggedIn(userWithRole(Role::Hr));
    $this->get(route('checklists.show', $this->budiList))->assertSee('>Edit<', false);
    $edit()->assertRedirect();
    expect($this->budiList->fresh()->items->pluck('status.value')->unique()->all())->toBe(['pending']);
});

it('lets the HOD edit until the checklist is reviewed, but only in their own department', function () {
    loggedIn($this->hod);
    $this->get(route('checklists.show', $this->budiList))->assertSee('>Edit<', false);
    $this->put(route('checklists.update', $this->budiList), ['items' => answers($this->budiList->items->pluck('id'), 'ongoing')])->assertRedirect();
    $this->put(route('checklists.update', $this->sariList), ['items' => answers($this->sariList->items->pluck('id'), 'ongoing')])->assertForbidden();
    $this->get(route('checklists.show', $this->sariList))->assertForbidden();

    expect($this->budiList->fresh()->items->first()->status->value)->toBe('ongoing')->and($this->sariList->fresh()->items->first()->status->value)->toBe('done');
});

it('lets only HR and administrators delete checklists', function () {
    loggedIn($this->hod);
    $this->delete(route('checklists.destroy', $this->budiList))->assertForbidden();
    $this->get(route('checklists.show', $this->budiList))->assertDontSee('>Hapus<', false);
    $this->post('/logout');

    loggedIn(userWithRole(Role::Hr));
    $this->get(route('checklists.show', $this->budiList))->assertSee('>Hapus<', false);
    $this->delete(route('checklists.destroy', $this->budiList))->assertRedirect();

    expect(Checklist::find($this->budiList->id))->toBeNull()->and(Checklist::find($this->sariList->id))->not->toBeNull();
});

it('shows the summary on the detail page', function () {
    $items = $this->budiList->items;
    $items[0]->update(['status' => 'ongoing']);
    $items[1]->update(['status' => 'pending', 'note' => 'Menunggu barang dari store']);
    loggedIn(userWithRole(Role::Gm));

    $this->get(route('checklists.show', $this->budiList))->assertOk()
        ->assertSee('Budi Santoso')->assertSee('GSA')->assertSee('HOD Uji')->assertSee('0%')->assertSee('(0/2 Done)')
        ->assertSee('1 Ongoing')->assertSee('1 Pending')->assertSee('Menunggu barang dari store')->assertSee('Belum direview');
});

it('lists checklists by date, limited to the departments the viewer may see', function () {
    loggedIn($this->hod);
    $this->get(route('checklists.index'))->assertOk()->assertSee('Budi Santoso')->assertDontSee('Sari Dewi');
    $this->get(route('checklists.index', ['department' => $this->hk->id]))->assertForbidden();
    $this->post('/logout');

    loggedIn(userWithRole(Role::Gm));
    $this->get(route('checklists.index'))->assertSee('Budi Santoso')->assertSee('Sari Dewi');
    $this->get(route('checklists.index', ['status' => 'reviewed']))->assertDontSee('Budi Santoso');
    $this->get(route('checklists.index', ['status' => 'saved', 'department' => $this->hk->id]))->assertSee('Sari Dewi')->assertDontSee('Budi Santoso');
    $this->post('/logout');

    loggedIn(User::factory()->for($this->budi)->create());
    $this->get(route('checklists.index'))->assertForbidden();
});
