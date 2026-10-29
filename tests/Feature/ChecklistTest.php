<?php

use App\Enums\ChecklistStatus;
use App\Enums\ItemStatus;
use App\Models\Checklist;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->fo = Department::factory()->create();
    $this->position = Position::factory()->for($this->fo)->create(['name' => 'Duty Manager']);
    $this->tasks = collect([
        Task::factory()->for($this->position)->create(['section' => 'Awal shift', 'title' => 'Take handover', 'sort' => 1]),
        Task::factory()->for($this->position)->create(['section' => 'Awal shift', 'title' => 'Read email', 'sort' => 2]),
    ]);
    Task::factory()->for($this->position)->create(['title' => 'Tugas lama', 'is_active' => false]);
    $this->user = User::factory()->for(Employee::factory()->for($this->fo))->create(['position_id' => $this->position->id]);
});

it('creates today\'s checklist from the active tasks of the position, once', function () {
    loggedIn($this->user);

    $response = $this->get('/checklist');
    $checklist = Checklist::sole();
    $response->assertRedirect(route('checklists.show', $checklist));
    $this->get('/checklist')->assertRedirect(route('checklists.show', $checklist));

    expect($checklist)->position_name->toBe('Duty Manager')->department_id->toBe($this->fo->id)
        ->and($checklist->items->pluck('title')->all())->toBe(['Take handover', 'Read email']);
    $this->get(route('checklists.show', $checklist))->assertOk()->assertSee('Take handover')->assertSee('Kirim untuk review');
});

it('asks staff without a checklist position to contact the administrator', function () {
    $this->user->update(['position_id' => null]);
    loggedIn($this->user);

    $this->get('/checklist')->assertOk()->assertSee('Jabatan checklist Anda belum diatur');
});

it('saves a draft and requires every status plus a reason for Pending before submitting', function () {
    loggedIn($this->user);
    $this->get('/checklist');
    $checklist = Checklist::sole();
    [$first, $second] = $checklist->items->all();

    $this->put(route('checklists.update', $checklist), ['action' => 'save', 'items' => [$first->id => ['status' => 'done']]])->assertRedirect();
    expect($first->fresh()->status)->toBe(ItemStatus::Done)->and($checklist->fresh()->status)->toBe(ChecklistStatus::Draft);

    $this->put(route('checklists.update', $checklist), ['action' => 'submit', 'items' => [$first->id => ['status' => 'done']]])
        ->assertSessionHasErrors("items.{$second->id}.status");
    $this->put(route('checklists.update', $checklist), ['action' => 'submit', 'items' => [$first->id => ['status' => 'done'], $second->id => ['status' => 'pending']]])
        ->assertSessionHasErrors("items.{$second->id}.note");

    $this->put(route('checklists.update', $checklist), ['action' => 'submit', 'items' => [
        $first->id => ['status' => 'done'], $second->id => ['status' => 'pending', 'note' => 'Email server mati'],
    ]])->assertRedirect();

    $checklist->refresh();
    expect($checklist->status)->toBe(ChecklistStatus::Submitted)->and($checklist->score())->toBe(50);
    // Sudah dikirim: tidak bisa diubah lagi.
    $this->put(route('checklists.update', $checklist), ['action' => 'save'])->assertForbidden();
});

it('ignores items that belong to another checklist', function () {
    $other = User::factory()->for(Employee::factory()->for($this->fo))->create(['position_id' => $this->position->id]);
    loggedIn($other);
    $this->get('/checklist');
    $otherItem = Checklist::sole()->items->first();
    $this->post('/logout');

    loggedIn($this->user);
    $this->get('/checklist');
    $mine = Checklist::where('employee_id', $this->user->employee_id)->sole();
    $this->put(route('checklists.update', $mine), ['action' => 'save', 'items' => [$otherItem->id => ['status' => 'done']]]);

    expect($otherItem->fresh()->status)->toBeNull();
});

it('lets the employee fix and resubmit a returned checklist', function () {
    loggedIn($this->user);
    $this->get('/checklist');
    $checklist = Checklist::sole();
    $checklist->update(['status' => ChecklistStatus::Returned, 'review_note' => 'Lengkapi', 'reviewed_by' => $this->user->id]);

    $items = $checklist->items->mapWithKeys(fn ($item) => [$item->id => ['status' => 'done']])->all();
    $this->put(route('checklists.update', $checklist), ['action' => 'submit', 'items' => $items])->assertRedirect();

    expect($checklist->fresh())->status->toBe(ChecklistStatus::Submitted)->reviewed_by->toBeNull();
});

it('keeps checklist history when the employee changes department or leaves', function () {
    loggedIn($this->user);
    $this->get('/checklist');
    $checklist = Checklist::sole();
    $this->post('/logout');

    $this->user->employee->update(['department_id' => Department::factory()->create()->id, 'is_active' => false]);
    $this->position->tasks()->delete();

    expect($checklist->fresh())->department_id->toBe($this->fo->id)
        ->and($checklist->fresh()->items->pluck('title')->all())->toBe(['Take handover', 'Read email']);
});
