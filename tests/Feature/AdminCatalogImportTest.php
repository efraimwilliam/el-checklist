<?php

use App\Enums\Role;
use App\Models\Department;
use App\Models\Position;
use App\Models\Task;

beforeEach(function () {
    $this->fo = Department::factory()->create(['name' => 'Front Office']);
    $this->hk = Department::factory()->create(['name' => 'Housekeeping']);
    $this->gsa = Position::factory()->for($this->fo)->create(['name' => 'GSA']);
    $this->ra = Position::factory()->for($this->hk)->create(['name' => 'Room Attendant']);
    $this->minibar = Task::factory()->for($this->ra)->create(['title' => 'Cek minibar', 'section' => 'Kamar', 'standard' => 'Sebelum 10.00']);
    $this->minibar->parameters()->create(['name' => 'Status', 'type' => 'status', 'sort' => 1]);
    $this->new = Position::factory()->for($this->fo)->create(['name' => 'Bellboy']);
    $this->hr = userWithRole(Role::Hr);
});

it('shows the catalog picker as department, position and task dropdowns on a position page', function () {
    loggedIn($this->hr);
    $this->get(route('admin.tasks.show', $this->new))->assertOk()
        ->assertSee('Ambil tugas dari kamus')->assertSee('Cek minibar')
        ->assertSee('data-pick-department', false)->assertSee('data-pick-position', false)
        ->assertSee('value="'.$this->hk->id.'"', false)->assertSee('value="'.$this->ra->id.'"', false);
});

it('copies the chosen catalog tasks with their parameters and skips duplicates', function () {
    Task::factory()->for($this->new)->create(['title' => 'cek minibar']);
    loggedIn($this->hr);

    $this->post(route('admin.tasks.import-catalog', $this->new), ['tasks' => [$this->minibar->id]])
        ->assertRedirect(route('admin.tasks.show', $this->new));
    expect($this->new->tasks()->count())->toBe(1);

    $this->post(route('admin.tasks.import-catalog', $this->new), ['tasks' => []])->assertSessionHasErrors('tasks');

    $fresh = Task::factory()->for($this->ra)->create(['title' => 'Ganti seprai']);
    $fresh->parameters()->create(['name' => 'Status', 'type' => 'status', 'sort' => 1]);
    $this->post(route('admin.tasks.import-catalog', $this->new), ['tasks' => [$fresh->id]]);

    $copy = $this->new->tasks()->where('title', 'Ganti seprai')->firstOrFail();
    expect($copy->parameters()->where('name', 'Status')->exists())->toBeTrue()
        ->and($copy->id)->not->toBe($fresh->id);
});
