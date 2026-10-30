<?php

use App\Checklists\ParameterRules;
use App\Enums\ItemStatus;
use App\Enums\Role;
use App\Models\ChecklistItemParameter;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ParameterTemplate;
use App\Models\Position;
use App\Models\Task;
use App\Models\User;
use App\Tasks\TaskParameters;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $fo = Department::factory()->create(['name' => 'Engineering']);
    $this->position = Position::factory()->for($fo)->create(['name' => 'Engineering Technician']);
    $this->task = Task::factory()->for($this->position)->create(['title' => 'Cek genset', 'sort' => 1]);
    $this->task->parameters()->createMany([
        ['name' => 'Status', 'type' => 'status', 'sort' => 1],
        ['name' => 'Voltase accu', 'type' => 'number', 'unit' => 'V', 'min_value' => 22, 'max_value' => 28, 'sort' => 2],
        ['name' => 'Oil level', 'type' => 'option', 'options' => 'Full, Low', 'normal_option' => 'Full', 'sort' => 3],
        ['name' => 'Sudah dicek', 'type' => 'checkbox', 'sort' => 4],
    ]);
    $this->employee = Employee::factory()->for($fo)->create();
    $this->checklist = makeChecklist($this->employee, $this->position, null, null);
    $this->item = $this->checklist->items->firstWhere('task_id', $this->task->id);
    $this->params = $this->item->parameters->keyBy('name');
});

function paramUrl($test, $checklist, $item)
{
    return route('checklists.items.update', [$checklist, $item]);
}

it('copies the task parameters onto the checklist item, and gives a Status parameter to tasks without any', function () {
    expect($this->item->parameters)->toHaveCount(4)
        ->and($this->params['Voltase accu']->unit)->toBe('V')
        ->and((float) $this->params['Voltase accu']->min_value)->toBe(22.0)
        ->and($this->params['Oil level']->options)->toBe('Full, Low');

    $plain = Task::factory()->for($this->position)->create(['title' => 'Tanpa parameter', 'sort' => 2]);
    $item = makeChecklist($this->employee, $this->position, CarbonImmutable::today()->subDay(), null)->items->firstWhere('task_id', $plain->id);
    expect($item->parameters->pluck('type')->all())->toBe(['status']);
});

it('judges a number against its limits, accepting a decimal comma', function (string $raw, string $state) {
    expect(ParameterRules::evaluate($this->params['Voltase accu'], $raw)['state'])->toBe($state);
})->with([
    'inside' => ['24,5', 'ok'],
    'above' => ['30', 'pending'],
    'below' => ['20', 'pending'],
    'empty' => ['', 'empty'],
]);

it('rejects text that is not a number, and an option outside its choices', function () {
    expect(ParameterRules::evaluate($this->params['Voltase accu'], 'dua puluh')['error'])->not->toBeNull()
        ->and(ParameterRules::evaluate($this->params['Oil level'], 'Penuh')['error'])->not->toBeNull()
        ->and(ParameterRules::evaluate($this->params['Oil level'], 'Full')['state'])->toBe('ok')
        ->and(ParameterRules::evaluate($this->params['Oil level'], 'Low')['state'])->toBe('pending');
});

it('derives the task status from all of its parameters', function () {
    $saveAll = fn (array $values) => app(\App\Checklists\ChecklistService::class)->saveParameters($this->item, $values, null, $this->employee->user ?? User::factory()->create());

    $status = fn () => $this->item->fresh()->status;
    $ids = fn (string $name) => (string) $this->params[$name]->id;

    $saveAll([$ids('Status') => 'done', $ids('Voltase accu') => '24', $ids('Oil level') => 'Full', $ids('Sudah dicek') => '1']);
    expect($status())->toBe(ItemStatus::Done);

    $saveAll([$ids('Voltase accu') => '30']);
    expect($status())->toBe(ItemStatus::Pending);

    $saveAll([$ids('Voltase accu') => '24', $ids('Oil level') => '']);
    expect($status())->toBeNull();
});

it('saves parameter values through the item API and returns the derived status', function () {
    loggedIn(userWithRole(Role::Admin));
    $volt = (string) $this->params['Voltase accu']->id;
    $status = (string) $this->params['Status']->id;

    $this->patchJson(paramUrl($this, $this->checklist, $this->item), ['parameters' => [$volt => '24,5', $status => 'ongoing']])
        ->assertOk()
        ->assertJsonPath("items.{$this->item->id}.status", 'ongoing')
        ->assertJsonPath("items.{$this->item->id}.parameters.{$volt}", '24.5');

    $this->patchJson(paramUrl($this, $this->checklist, $this->item), ['parameters' => [$volt => 'abc']])
        ->assertStatus(422)->assertJsonValidationErrors("parameters.{$volt}");
});

it('keeps the legacy single status request working for the Status parameter', function () {
    loggedIn(userWithRole(Role::Admin));

    $this->patchJson(paramUrl($this, $this->checklist, $this->item), ['status' => 'pending', 'note' => 'menunggu suku cadang'])->assertOk();

    expect($this->item->fresh()->status)->toBe(ItemStatus::Pending)->and($this->item->fresh()->note)->toBe('menunggu suku cadang');
});

it('normalises parameter rows and rejects impossible limits and options', function () {
    $rows = ParameterRules::normalize([
        ['name' => ' Voltase ', 'type' => 'number', 'unit' => ' V ', 'min_value' => '22', 'max_value' => '28', 'options' => 'x'],
        ['name' => 'Oil', 'type' => 'option', 'options' => 'Full,, Low, Full', 'normal_option' => 'Full'],
    ]);
    expect($rows[0])->toMatchArray(['name' => 'Voltase', 'unit' => 'V', 'options' => null])
        ->and($rows[1]['options'])->toBe('Full, Low');

    expect(fn () => ParameterRules::normalize([['name' => 'X', 'type' => 'number', 'min_value' => '30', 'max_value' => '20']]))->toThrow(ValidationException::class);
    expect(fn () => ParameterRules::normalize([['name' => 'Oil', 'type' => 'option', 'options' => '']]))->toThrow(ValidationException::class);
    expect(fn () => ParameterRules::normalize([['name' => 'Oil', 'type' => 'option', 'options' => 'Full', 'normal_option' => 'Penuh']]))->toThrow(ValidationException::class);
});

it('stores each parameter type used as a reusable template, without duplicates', function () {
    $rows = ParameterRules::normalize([['name' => 'Tekanan udara', 'type' => 'number', 'unit' => 'bar', 'min_value' => '6', 'max_value' => '8']]);

    TaskParameters::sync($this->task, $rows);
    TaskParameters::sync($this->task, $rows);

    expect(ParameterTemplate::where('name', 'Tekanan udara')->where('type', 'number')->count())->toBe(1)
        ->and(TaskParameters::templates()->pluck('name')->all())->toContain('Tekanan udara')
        ->and($this->task->fresh()->parameters()->where('name', 'Tekanan udara')->count())->toBe(1);
});
