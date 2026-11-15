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
            'features' => ChecklistSettings::FEATURES,
            'quickOptions' => ChecklistSettings::QUICK_ENTRY,
            'quickTermCount' => \App\Models\QuickTerm::count(),
            'quickStates' => collect(ChecklistSettings::QUICK_ENTRY)->map(fn ($option, $key) => $settings->get($key) !== '0')->all(),
            'quickOtherDepartmentId' => $settings->quickOtherDepartmentId(),
            'allDepartments' => Department::where('is_active', true)->orderBy('sort')->orderBy('name')->get(),
            'defaultRequestDepartmentId' => \App\Models\GuestRequest::defaultDepartmentId(),
            'featureStates' => collect(ChecklistSettings::FEATURES)->map(fn ($feature, $key) => $settings->featureEnabled($key))->all(),
            'retention' => $settings->photoRetentionDays(),
            'requirements' => ChecklistSettings::REQUIREMENTS,
            'departments' => Department::where('is_active', true)->when($allowedIds !== null, fn ($q) => $q->whereIn('id', $allowedIds))->orderBy('sort')->orderBy('name')->get(),
        ]);
    }

    /** Aturan checklist (Administrator): boleh mengisi checklist untuk hari yang akan datang atau tidak. (Obrolan pindah ke Fitur.) */
    public function updateRules(Request $request, ChecklistSettings $settings): RedirectResponse
    {
        Gate::authorize('manage-access');
        $settings->put('checklist_future_fill', $request->boolean('future_fill') ? '1' : '0');

        return back()->with('status', __('Pengaturan disimpan.'));
    }

    /** Fitur (Administrator): nyalakan / matikan modul untuk semua pengguna. Kotak yang tidak dicentang = mati. Tercatat di Log aplikasi (Setting). */
    public function updateFeatures(Request $request, ChecklistSettings $settings): RedirectResponse
    {
        Gate::authorize('manage-access');
        $chosen = (array) $request->input('features', []);
        foreach (array_keys(ChecklistSettings::FEATURES) as $key) {
            $settings->put(ChecklistSettings::featureSettingKey($key), filter_var($chosen[$key] ?? false, FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
        }

        return redirect()->to(route('admin.settings.edit').'#fitur')->with('status', __('Pengaturan fitur disimpan.'));
    }

    /** Ketik bebas di obrolan (Administrator): nyala / mati per bagian + tujuan bawaan permintaan "Lainnya". */
    public function updateQuickEntry(Request $request, ChecklistSettings $settings): RedirectResponse
    {
        Gate::authorize('manage-access');
        $data = $request->validate(['quick' => ['nullable', 'array'], 'quick_other_department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('is_active', true)]]);
        foreach (array_keys(ChecklistSettings::QUICK_ENTRY) as $key) {
            $settings->put($key, filter_var($data['quick'][$key] ?? false, FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
        }
        // Tujuan "Lainnya" disimpan di jenis Lainnya (Kamus ketik bebas memakai data yang sama).
        \App\Models\GuestRequestKind::where('key', 'other')->update(['department_id' => $data['quick_other_department_id'] ?? null]);
        \App\Models\GuestRequest::kinds()->refresh();

        return redirect()->to(route('admin.settings.edit').'#ketik')->with('status', __('Pengaturan ketik bebas disimpan.'));
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
