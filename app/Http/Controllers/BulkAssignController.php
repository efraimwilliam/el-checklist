<?php

namespace App\Http\Controllers;

use App\Attendance\AttendanceUnavailable;
use App\Checklists\BulkAssignService;
use App\Models\Department;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Satu tombol: buatkan checklist hari itu untuk semua staff yang masuk kerja (menurut jadwal Absensi), dan atur jam pembuatan otomatis.
 * HOD hanya departemennya; HR / Administrator semua departemen.
 */
class BulkAssignController extends Controller
{
    public function store(Request $request, BulkAssignService $service): RedirectResponse
    {
        Gate::authorize('manage-team-tasks');
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'department' => ['nullable', 'regex:/^(all|\d+)$/'],
        ], ['date.before_or_equal' => __('Tanggal tidak boleh di masa depan.')]);
        $user = $request->user();
        $allowed = $user->accessibleDepartmentIds() ?? Department::where('is_active', true)->pluck('id')->all();

        $requested = (string) ($data['department'] ?? 'all');
        if ($requested !== 'all') {
            abort_unless(in_array((int) $requested, $allowed, true), 403);
            $allowed = [(int) $requested];
        }

        try {
            $summary = $service->run($allowed, CarbonImmutable::parse($data['date']), $user);
        } catch (AttendanceUnavailable) {
            return back()->withInput()->withErrors(['assign' => __('Sistem absensi tidak dapat dihubungi, jadi jadwal belum bisa dibaca. Coba lagi beberapa saat.')]);
        }

        return redirect()->route('fill.create', ['date' => $data['date']])->with('assign_summary', $summary);
    }

    /** Jam pembuatan otomatis per departemen; kosong = mati. */
    public function auto(Request $request, Department $department): RedirectResponse
    {
        Gate::authorize('manage-team-tasks');
        abort_unless($request->user()->canAccessDepartment($department->id), 403);
        $data = $request->validate(['auto_assign_at' => ['nullable', 'date_format:H:i']], ['auto_assign_at.date_format' => __('Isi jam dengan format JJ:MM, mis. 05:00.')]);

        $department->update(['auto_assign_at' => $data['auto_assign_at'] ?? null, 'auto_assigned_on' => null]);

        return back()->with('status', $department->auto_assign_at
            ? __('Pembuatan otomatis :name diatur setiap hari pukul :time.', ['name' => $department->name, 'time' => $data['auto_assign_at']])
            : __('Pembuatan otomatis :name dimatikan.', ['name' => $department->name]));
    }
}
