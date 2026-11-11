<?php

namespace App\Http\Controllers\Admin;

use App\Attendance\AttendanceUnavailable;
use App\Attendance\EmployeePhotoSync;
use App\Attendance\EmployeeSyncService;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Administrator mengatur siapa yang boleh memakai aplikasi, perannya, dan departemen HOD. Jabatan checklist diatur di Tim → Jabatan. */
class AccessController extends Controller
{
    public function index(): View
    {
        Gate::authorize('manage-access');

        // Semua pegawai dimuat sekali; cari, sortir, dan halaman dikerjakan di browser (tanpa memuat ulang).
        $employees = Employee::with(['department', 'checklistPosition', 'user.position', 'user.departments'])
            ->orderByDesc('is_active')->orderBy('name')->get();

        return view('admin.access.index', [
            'employees' => $employees,
            'lastSync' => Cache::get(EmployeeSyncService::LAST_SYNC_CACHE),
        ]);
    }

    public function edit(Employee $employee): View
    {
        Gate::authorize('manage-access');

        return view('admin.access.edit', [
            'employee' => $employee->load(['department', 'checklistPosition', 'user.departments']),
            'roles' => Role::cases(),
            'departments' => Department::orderBy('sort')->orderBy('name')->get(),
            'positions' => Position::with('department')->where('is_active', true)->orderBy('department_id')->orderBy('sort')->get(),
        ]);
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        Gate::authorize('manage-access');
        $data = $request->validate([
            'is_active' => ['boolean'],
            'role' => ['required', Rule::enum(Role::class)],
            'departments' => ['array'],
            'departments.*' => ['integer', 'exists:departments,id'],
        ]);
        $role = Role::from($data['role']);
        $active = $request->boolean('is_active');

        // Administrator tidak bisa mencabut aksesnya sendiri (mencegah tidak ada admin sama sekali).
        if ($employee->id === $request->user()->employee_id && (! $active || $role !== Role::Admin)) {
            return back()->withErrors(['role' => __('Anda tidak bisa mencabut akses Administrator Anda sendiri.')]);
        }

        // Jabatan checklist tidak diatur di sini (Tim → Jabatan), jadi tidak disentuh.
        $user = User::updateOrCreate(['employee_id' => $employee->id], ['role' => $role, 'is_active' => $active]);
        // Departemen hanya berarti untuk HOD; peran lain dikosongkan supaya akses lama tidak tertinggal.
        $user->departments()->sync($role === Role::Hod ? ($data['departments'] ?? []) : []);

        return redirect()->route('admin.access.index')->with('status', __('Akses :name disimpan.', ['name' => $employee->name]));
    }

    public function sync(EmployeeSyncService $sync, EmployeePhotoSync $photos): RedirectResponse
    {
        Gate::authorize('manage-access');
        try {
            $result = $sync->sync();
            $photos->refresh();
        } catch (AttendanceUnavailable $e) {
            return back()->withErrors(['sync' => $e->getMessage()]);
        }

        return back()->with('status', __('Sinkron selesai: :employees pegawai, :deactivated dinonaktifkan.', $result));
    }
}
