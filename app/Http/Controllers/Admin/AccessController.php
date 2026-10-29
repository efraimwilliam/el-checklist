<?php

namespace App\Http\Controllers\Admin;

use App\Attendance\AttendanceUnavailable;
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

/** Administrator mengatur siapa yang boleh memakai aplikasi, perannya, jabatan checklist, dan departemen HOD. */
class AccessController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('manage-access');
        $search = trim((string) $request->query('q'));

        $employees = Employee::with(['department', 'user.position', 'user.departments'])
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('username', 'like', "%{$search}%")))
            ->when($request->boolean('with_access'), fn ($q) => $q->whereHas('user', fn ($q) => $q->where('is_active', true)))
            ->orderByDesc('is_active')->orderBy('name')
            ->paginate(50)->withQueryString();

        return view('admin.access.index', [
            'employees' => $employees,
            'search' => $search,
            'lastSync' => Cache::get(EmployeeSyncService::LAST_SYNC_CACHE),
        ]);
    }

    public function edit(Employee $employee): View
    {
        Gate::authorize('manage-access');

        return view('admin.access.edit', [
            'employee' => $employee->load(['department', 'user.departments']),
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
            'position_id' => ['nullable', 'exists:positions,id'],
            'departments' => ['array'],
            'departments.*' => ['integer', 'exists:departments,id'],
        ]);
        $role = Role::from($data['role']);
        $active = $request->boolean('is_active');

        // Administrator tidak bisa mencabut aksesnya sendiri (mencegah tidak ada admin sama sekali).
        if ($employee->id === $request->user()->employee_id && (! $active || $role !== Role::Admin)) {
            return back()->withErrors(['role' => __('Anda tidak bisa mencabut akses Administrator Anda sendiri.')]);
        }

        $user = User::updateOrCreate(['employee_id' => $employee->id], [
            'role' => $role, 'is_active' => $active, 'position_id' => $data['position_id'] ?? null,
        ]);
        // Departemen hanya berarti untuk HOD; peran lain dikosongkan supaya akses lama tidak tertinggal.
        $user->departments()->sync($role === Role::Hod ? ($data['departments'] ?? []) : []);

        return redirect()->route('admin.access.index')->with('status', __('Akses :name disimpan.', ['name' => $employee->name]));
    }

    public function sync(EmployeeSyncService $sync): RedirectResponse
    {
        Gate::authorize('manage-access');
        try {
            $result = $sync->sync();
        } catch (AttendanceUnavailable $e) {
            return back()->withErrors(['sync' => $e->getMessage()]);
        }

        return back()->with('status', __('Sinkron selesai: :employees pegawai, :deactivated dinonaktifkan.', $result));
    }
}
