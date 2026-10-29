<?php

namespace App\Http\Controllers\Admin;

use App\Checklists\ChecklistSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Pengaturan oleh Administrator: foto bukti wajib / opsional dan masa simpannya. */
class SettingsController extends Controller
{
    public function edit(ChecklistSettings $settings): View
    {
        Gate::authorize('manage-access');

        return view('admin.settings', [
            'requirement' => $settings->photoRequirement(),
            'retention' => $settings->photoRetentionDays(),
            'requirements' => ChecklistSettings::REQUIREMENTS,
        ]);
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
