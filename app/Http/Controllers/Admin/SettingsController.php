<?php

namespace App\Http\Controllers\Admin;

use App\Checklists\ChecklistSettings;
use App\Health\SystemHealth;
use App\Models\Department;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Pengaturan: foto bukti (Administrator) dan pembuatan otomatis tiap hari per departemen (HOD untuk departemennya, HR, Administrator).
 */
class SettingsController extends Controller
{
    public function edit(Request $request, ChecklistSettings $settings, SystemHealth $health): View
    {
        abort_unless(Gate::allows('manage-access') || Gate::allows('manage-team-tasks'), 403);
        $allowedIds = $request->user()->accessibleDepartmentIds();
        $healthGroups = Gate::allows('manage-access') ? $health->groups() : [];

        return view('admin.settings', [
            'healthGroups' => $healthGroups,
            'healthSummary' => $healthGroups ? $health->summary($healthGroups) : null,
            'checkedAt' => now(),
            'requirement' => $settings->photoRequirement(),
            'futureFill' => $settings->futureFillAllowed(),
            'retention' => $settings->photoRetentionDays(),
            'requirements' => ChecklistSettings::REQUIREMENTS,
            'departments' => Department::where('is_active', true)->when($allowedIds !== null, fn ($q) => $q->whereIn('id', $allowedIds))->orderBy('sort')->orderBy('name')->get(),
        ]);
    }

    /** Aturan checklist (Administrator): boleh mengisi checklist untuk hari yang akan datang atau tidak. */
    public function updateRules(Request $request, ChecklistSettings $settings): RedirectResponse
    {
        Gate::authorize('manage-access');
        $settings->put('checklist_future_fill', $request->boolean('future_fill') ? '1' : '0');

        return back()->with('status', __('Pengaturan disimpan.'));
    }

    public function update(Request $request, ChecklistSettings $settings): RedirectResponse
    {
        Gate::authorize('manage-access');
        $data = $request->validate([
            'photo_requirement' => ['required', Rule::in(array_keys(ChecklistSettings::REQUIREMENTS))],
            'photo_retention_days' => ['required', 'integer', 'min:0', 'max:3650'],
        ], ['photo_retention_days.*' => __('Masa simpan harus berupa angka hari antara 0 dan 3650.')]);

        $settings->put('photo_requirement', $data['photo_requirement']);
        $settings->put('photo_retention_days', $data['photo_retention_days']);

        return back()->with('status', __('Pengaturan disimpan.'));
    }
}
